<?php
declare(strict_types=1);
/**
 * Grant Review Document Parser
 * Parses Word documents (.docx) containing grant review reports
 */

class DocumentParser {
    private $errors = [];

    /**
     * Parse a .docx file and extract review data
     */
    public function parseFile($filePath, array $sectionDefinitions = null) {
        $this->errors = [];

        if (!file_exists($filePath)) {
            $this->errors[] = "File not found: $filePath";
            return null;
        }

        // Extract text from docx
        $text = $this->extractTextFromDocx($filePath);
        if ($text === false) {
            if (empty($this->errors)) {
                $this->errors[] = "Failed to extract text from document";
            }
            return null;
        }

        // Parse the extracted text
        $data = $this->parseReviewText($text, $sectionDefinitions);

        return $data;
    }

    /**
     * Extract text from .docx file
     */
    private function extractTextFromDocx($filePath) {
        if (class_exists('ZipArchive')) {
            return $this->extractTextWithZipArchive($filePath);
        }

        if ($this->isUnzipAvailable()) {
            return $this->extractTextWithUnzip($filePath);
        }

        $this->errors[] = "PHP Zip extension is missing and system 'unzip' command is unavailable.";
        return false;
    }

    private function extractTextWithZipArchive($filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $this->errors[] = "Unable to open document archive.";
            return false;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            $this->errors[] = "Document XML could not be read from archive.";
            return false;
        }

        return $this->stripDocumentXml($xml);
    }

    private function extractTextWithUnzip($filePath) {
        $command = 'unzip -p ' . escapeshellarg($filePath) . ' word/document.xml 2>/dev/null';
        $output = [];
        $returnVar = null;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->errors[] = "System unzip command failed to extract document.xml (exit code $returnVar).";
            return false;
        }

        $xml = implode("\n", $output);
        if (trim($xml) === '') {
            $this->errors[] = "Document XML extracted via unzip is empty.";
            return false;
        }

        return $this->stripDocumentXml($xml);
    }

    private function isUnzipAvailable() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        if (function_exists('shell_exec')) {
            $result = shell_exec('command -v unzip 2>/dev/null');
            $which = trim((string) ($result ?? ''));
            if ($which !== '') {
                return $cached = true;
            }
        }

        $commonPaths = ['/usr/bin/unzip', '/bin/unzip', '/usr/local/bin/unzip'];
        foreach ($commonPaths as $path) {
            if (is_executable($path)) {
                return $cached = true;
            }
        }

        return $cached = false;
    }

    private function stripDocumentXml($xml) {
        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            if (@$dom->loadXML($xml)) {
                $xpath = new DOMXPath($dom);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $paragraphs = $xpath->query('//w:p');
                $lines = [];

                foreach ($paragraphs as $paragraph) {
                    $texts = [];
                    foreach ($xpath->query('.//w:t', $paragraph) as $run) {
                        $texts[] = $run->textContent;
                    }

                    $line = trim(preg_replace('/\s+/u', ' ', implode('', $texts)));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }

                if (!empty($lines)) {
                    return $this->normalizePlainText(implode("\n", $lines));
                }
            }
        }

        return $this->normalizePlainText($this->fallbackPlainText($xml));
    }

    private function fallbackPlainText($xml) {
        $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $xml = preg_replace('/<\/w:tbl>/', "\n", $xml);
        return strip_tags($xml);
    }

    private function normalizePlainText($text) {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{2,}/", "\n", $text);
        return trim($text);
    }

    /**
     * Parse the review text and extract structured data
     */
    private function parseReviewText($text, array $sectionDefinitions = null) {
        $text = $this->normalizePlainText($text);
        $data = [
            'application_title' => '',
            'applicant_name' => '',
            'grant_type' => '',
            'overall_impact' => ['score' => 0, 'explanation' => ''],
            'relevance' => ['score' => 0, 'explanation' => ''],
            'criteria' => [],
            'budget' => ['acceptable' => null, 'modifications' => '']
        ];

        // Extract application title and PI
        if (preg_match('/Application Title:\s*(.+?)\s*Principal Investigator:/s', $text, $matches)) {
            $data['application_title'] = trim($matches[1]);
        }

        if (preg_match('/Principal Investigator:\s*(.+?)\s*(?:Overall Impact|$)/s', $text, $matches)) {
            $data['applicant_name'] = trim(str_replace('Dr.', '', $matches[1]));
            $data['applicant_name'] = trim($data['applicant_name']);
        }

        if ($sectionDefinitions) {
            $data['sections'] = $this->extractSectionsByDefinitions($text, $sectionDefinitions);
        } else {
            // Determine grant type based on content
            if (stripos($text, 'Budget and Period of Support') !== false) {
                $data['grant_type'] = 'Developmental';
            } else {
                $data['grant_type'] = 'Pilot';
            }
            $data['grant_type'] = $this->normalizeGrantType($data['grant_type']);
        }

        if ($sectionDefinitions) {
            return $data;
        }

        $sectionTerminators = [
            'Relevance of the proposal to the specific focus of the RFA',
            'Relevance of the proposal',
            'Relevance',
            'Significance',
            'Investigator(s)',
            'Innovation',
            'Approach',
            'Environment',
            'Mentoring Team/Plan & Pathway to External Funding',
            'Budget and Period of Support',
            'Budget'
        ];

        // Extract Overall Impact
        $overall = $this->extractScoredSection($text, ['Overall Impact'], $sectionTerminators);
        if ($overall) {
            $data['overall_impact'] = $overall;
        }

        // Extract Relevance
        $relevanceTerminators = [
            'Significance',
            'Investigator(s)',
            'Innovation',
            'Approach',
            'Environment',
            'Mentoring Team/Plan & Pathway to External Funding',
            'Budget and Period of Support',
            'Budget'
        ];
        $relevance = $this->extractScoredSection(
            $text,
            [
                'Relevance of the proposal to the specific focus of the RFA',
                'Relevance of the proposal',
                'Relevance'
            ],
            $relevanceTerminators
        );
        if ($relevance) {
            $data['relevance'] = $relevance;
        }

        // Extract criteria
        $criteriaNames = [
            'Significance',
            'Investigator(s)',
            'Innovation',
            'Approach',
            'Environment',
            'Mentoring Team/Plan & Pathway to External Funding'
        ];

        foreach ($criteriaNames as $index => $criterionName) {
            $remaining = array_slice($criteriaNames, $index + 1);

            $criterionTerminators = array_merge(
                $remaining,
                ['Budget and Period of Support', 'Budget']
            );

            $criterionData = $this->extractCriterionSection($text, $criterionName, $criterionTerminators);
            if ($criterionData) {
                $data['criteria'][] = $criterionData;
            }
        }

        // Extract budget (Developmental only)
        if ($this->isDevelopmentalType($data['grant_type'])) {
            if (preg_match('/Budget.*?Acceptable:\s*(Yes|No)/s', $text, $matches)) {
                $data['budget']['acceptable'] = ($matches[1] === 'Yes');
            }
            if (preg_match('/Modifications:\s*(.+?)$/s', $text, $matches)) {
                $data['budget']['modifications'] = trim($matches[1]);
            }
        }

        return $data;
    }

    private function extractScoredSection($text, array $labels, array $terminators) {
        $labelPattern = $this->buildAlternationPattern($labels);
        $terminatorPattern = $this->buildTerminatorLookahead($terminators);
        $pattern = '/(?:^|\n)\s*' . $labelPattern . '.*?Score:\s*(\d+)\s*(?:Explanation[^\n:]*:)?\s*(.+?)' . $terminatorPattern . '/is';

        if (preg_match($pattern, $text, $matches)) {
            return [
                'score' => intval($matches[1]),
                'explanation' => trim($matches[2])
            ];
        }

        return null;
    }

    private function extractCriterionSection($text, $criterionName, array $terminators) {
        $terminatorPattern = $this->buildTerminatorLookahead($terminators);
        $pattern = '/(?:^|\n)\s*' . preg_quote($criterionName, '/') .
                   '.*?Score:\s*(\d+)\s*(.+?)' . $terminatorPattern . '/is';

        if (preg_match($pattern, $text, $matches)) {
            $sectionBody = trim($matches[2]);
            [$strengths, $weaknesses, $summative] = $this->extractStrengthsWeaknesses($sectionBody);

            return [
                'name' => $criterionName,
                'score' => intval($matches[1]),
                'summative_comments' => $summative,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses
            ];
        }

        return null;
    }

    private function extractStrengthsWeaknesses($sectionText) {
        $strengths = '';
        $weaknesses = '';

        if (preg_match('/Strengths?(?:\s*[:\-])?\s*(.+?)(?=Weaknesses?|$)/is', $sectionText, $matches)) {
            $strengths = trim($matches[1]);
        }

        if (preg_match('/Weaknesses?(?:\s*[:\-])?\s*(.+)$/is', $sectionText, $matches)) {
            $weaknesses = trim($matches[1]);
        }

        $summative = preg_replace('/Strengths?(?:\s*[:\-])?\s*(.+?)(?=Weaknesses?|$)/is', '', $sectionText);
        $summative = preg_replace('/Weaknesses?(?:\s*[:\-])?\s*(.+)$/is', '', $summative);
        $summative = trim($summative);

        return [$strengths, $weaknesses, $summative];
    }

    private function extractSectionsByDefinitions($text, array $sectionDefinitions) {
        $sections = [];
        $definitionMap = [];
        $sectionNames = [];

        foreach ($sectionDefinitions as $definition) {
            if (!isset($definition['name']) || $definition['name'] === '') {
                continue;
            }
            $name = (string) $definition['name'];
            $key = strtolower($name);
            $definitionMap[$key] = $definition;
            $sectionNames[] = $name;
        }

        if (empty($sectionNames)) {
            return $sections;
        }

        $sectionOffsets = $this->findSectionOffsets($text, $sectionNames);
        $textLength = strlen($text);

        foreach ($sectionOffsets as $index => $entry) {
            $start = $entry['offset'];
            $end = ($index + 1 < count($sectionOffsets)) ? $sectionOffsets[$index + 1]['offset'] : $textLength;
            $rawSection = trim(substr($text, $start, $end - $start));
            if ($rawSection === '') {
                continue;
            }

            $lines = explode("\n", $rawSection);
            $headerLine = array_shift($lines);
            $body = trim(implode("\n", $lines));

            $key = strtolower($entry['name']);
            $definition = $definitionMap[$key] ?? ['name' => $entry['name']];
            $isScored = !empty($definition['is_scored']);

            $score = null;
            if ($isScored) {
                if (preg_match('/Score\\s*[:\\-]?\\s*(\\d+)/i', $rawSection, $matches)) {
                    $score = (int) $matches[1];
                }
            }

            $body = preg_replace('/Score\\s*[:\\-]?\\s*\\d+/i', '', $body, 1);
            $body = trim($body);
            [$strengths, $weaknesses, $summative] = $this->extractStrengthsWeaknesses($body);

            if ($summative === '' && $strengths === '' && $weaknesses === '' && $body !== '') {
                $summative = $body;
            }

            $sections[] = [
                'name' => $definition['name'],
                'score' => $isScored ? $score : null,
                'summative_comments' => $summative,
                'strengths' => $strengths,
                'weaknesses' => $weaknesses
            ];
        }

        return $sections;
    }

    private function findSectionOffsets($text, array $sectionNames) {
        $sectionNames = array_values(array_unique(array_filter($sectionNames, function ($name) {
            return is_string($name) && trim($name) !== '';
        })));

        usort($sectionNames, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        $lines = explode("\n", $text);
        $offset = 0;
        $found = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                foreach ($sectionNames as $name) {
                    if (stripos($trimmed, $name) === 0) {
                        if (!isset($found[$name])) {
                            $found[$name] = $offset;
                        }
                        break;
                    }
                }
            }

            $offset += strlen($line) + 1;
        }

        $entries = [];
        foreach ($found as $name => $pos) {
            $entries[] = ['name' => $name, 'offset' => $pos];
        }
        usort($entries, function ($a, $b) {
            return $a['offset'] <=> $b['offset'];
        });

        return $entries;
    }

    private function buildTerminatorLookahead(array $terminators) {
        $terminators = array_values(array_filter($terminators));
        if (empty($terminators)) {
            return '(?=\z)';
        }

        $escaped = array_map(function ($label) {
            return preg_quote($label, '/');
        }, $terminators);

        return '(?=(?:\n\s*(?:' . implode('|', $escaped) . ')(?:\n|$))|\z)';
    }

    private function buildAlternationPattern(array $labels) {
        $escaped = array_map(function ($label) {
            return preg_quote($label, '/');
        }, $labels);

        return '(?:' . implode('|', $escaped) . ')';
    }

    private function normalizeGrantType(string $grantType): string {
        $grantType = trim($grantType);
        if (strcasecmp($grantType, 'Pilot') === 0) {
            return 'TRANSCEND Pilot';
        }
        if (strcasecmp($grantType, 'Developmental') === 0) {
            return 'TRANSCEND Developmental';
        }
        return $grantType;
    }

    private function isDevelopmentalType(string $grantType): bool {
        return stripos($grantType, 'Developmental') !== false;
    }

    /**
     * Get parsing errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Validate parsed data
     */
    public function validateData($data, array $sectionDefinitions = null) {
        $errors = [];

        if (empty($data['application_title'])) {
            $errors[] = "Application title is required";
        }

        if (empty($data['applicant_name'])) {
            $errors[] = "Applicant name is required";
        }

        if ($sectionDefinitions) {
            $parsedSections = $data['sections'] ?? [];
            $sectionMap = [];
            foreach ($parsedSections as $section) {
                if (!isset($section['name'])) {
                    continue;
                }
                $sectionMap[strtolower($section['name'])] = $section;
            }

            foreach ($sectionDefinitions as $definition) {
                $name = $definition['name'] ?? '';
                if ($name === '') {
                    continue;
                }
                $key = strtolower($name);
                $isRequired = !empty($definition['is_required']);
                $isScored = !empty($definition['is_scored']);
                $scoreMin = isset($definition['score_min']) ? (int) $definition['score_min'] : null;
                $scoreMax = isset($definition['score_max']) ? (int) $definition['score_max'] : null;

                $parsed = $sectionMap[$key] ?? null;
                if (!$parsed) {
                    if ($isRequired) {
                        $errors[] = "Missing required section: $name";
                    }
                    continue;
                }

                if ($isScored) {
                    if ($parsed['score'] === null) {
                        $errors[] = "Score required for $name";
                    } elseif ($scoreMin !== null && $scoreMax !== null) {
                        if ($parsed['score'] < $scoreMin || $parsed['score'] > $scoreMax) {
                            $errors[] = "Score for $name must be between $scoreMin and $scoreMax";
                        }
                    } elseif ($parsed['score'] < 1 || $parsed['score'] > 9) {
                        $errors[] = "Score for $name must be between 1 and 9";
                    }
                }

                if ($isRequired) {
                    $hasCritique = !empty($parsed['summative_comments'])
                        || !empty($parsed['strengths'])
                        || !empty($parsed['weaknesses']);
                    if (!$hasCritique) {
                        $errors[] = "Critique required for $name";
                    }
                }
            }

            return $errors;
        }

        $validTypes = ['Pilot', 'Developmental', 'TRANSCEND Pilot', 'TRANSCEND Developmental'];
        if (!in_array($data['grant_type'], $validTypes, true)) {
            $errors[] = "Invalid grant type";
        }

        if ($data['overall_impact']['score'] < 1 || $data['overall_impact']['score'] > 9) {
            $errors[] = "Overall impact score must be between 1 and 9";
        }

        if ($data['relevance']['score'] < 1 || $data['relevance']['score'] > 9) {
            $errors[] = "Relevance score must be between 1 and 9";
        }

        if (count($data['criteria']) < 5) {
            $errors[] = "Expected at least 5 review criteria, found " . count($data['criteria']);
        }

        foreach ($data['criteria'] as $criterion) {
            if ($criterion['score'] < 1 || $criterion['score'] > 9) {
                $errors[] = "Score for {$criterion['name']} must be between 1 and 9";
            }
        }

        return $errors;
    }
}
