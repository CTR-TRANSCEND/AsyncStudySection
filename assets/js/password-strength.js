/**
 * Password Strength Meter
 * SPEC-AUTH-001.7: Password Strength Validation
 *
 * Features:
 * - Real-time password strength estimation
 * - Visual feedback (color-coded meter)
 * - Strength label and suggestions
 * - Character type counting
 */

class PasswordStrengthMeter {
    constructor(inputSelector, meterSelector, labelSelector, feedbackSelector = null) {
        this.passwordInput = document.querySelector(inputSelector);
        this.meterBar = document.querySelector(meterSelector);
        this.strengthLabel = document.querySelector(labelSelector);
        this.feedbackList = feedbackSelector ? document.querySelector(feedbackSelector) : null;

        if (this.passwordInput) {
            this.passwordInput.addEventListener('input', () => this.updateStrength());
        }
    }

    updateStrength() {
        const password = this.passwordInput.value;
        const result = this.calculateStrength(password);
        this.displayResult(result);
    }

    calculateStrength(password) {
        let score = 0;
        const feedback = [];

        // Length scoring
        const length = password.length;
        if (length === 0) {
            return { score: 0, label: '', feedback: [] };
        } else if (length < 8) {
            feedback.push('Use at least 8 characters');
        } else if (length >= 12) {
            score += 30;
        } else if (length >= 8) {
            score += 20;
        }

        // Character variety
        const hasLower = /[a-z]/.test(password);
        const hasUpper = /[A-Z]/.test(password);
        const hasDigit = /[0-9]/.test(password);
        const hasSymbol = /[^a-zA-Z0-9]/.test(password);

        const typeCount = [hasLower, hasUpper, hasDigit, hasSymbol].filter(Boolean).length;
        score += typeCount * 15;

        if (typeCount < 2) {
            feedback.push('Mix uppercase, lowercase, numbers, and symbols');
        }

        // Complexity bonus
        if (length >= 12 && typeCount >= 3) {
            score += 10;
        }

        // Pattern penalties
        if (/^[a-zA-Z]+$/.test(password)) {
            score -= 10;
            feedback.push('Add numbers or symbols');
        }

        if (/^[0-9]+$/.test(password)) {
            score -= 10;
            feedback.push('Add letters');
        }

        if (/(.)\1{2,}/.test(password)) {
            score -= 10;
            feedback.push('Avoid repeated characters');
        }

        // Common patterns penalty
        const commonPatterns = [
            /123456/, /abcdef/, /qwerty/, /password/i,
            /admin/i, /letmein/i, /welcome/i
        ];
        for (const pattern of commonPatterns) {
            if (pattern.test(password)) {
                score -= 20;
                feedback.push('Avoid common patterns');
                break;
            }
        }

        // Cap score at 0-100
        score = Math.max(0, Math.min(100, score));

        // Determine label
        let label;
        if (score < 20) {
            label = 'Weak';
        } else if (score < 40) {
            label = 'Fair';
        } else if (score < 60) {
            label = 'Good';
        } else if (score < 80) {
            label = 'Strong';
        } else {
            label = 'Very Strong';
        }

        return {
            score: score,
            label: label,
            feedback: feedback
        };
    }

    displayResult(result) {
        if (!this.meterBar) return;

        // Update meter
        this.meterBar.style.width = result.score + '%';
        this.meterBar.className = 'password-strength-meter-fill';

        if (result.score === 0) {
            this.meterBar.style.backgroundColor = '#e0e0e0';
        } else if (result.score < 40) {
            this.meterBar.style.backgroundColor = '#f44336'; // Red
        } else if (result.score < 60) {
            this.meterBar.style.backgroundColor = '#ff9800'; // Orange
        } else if (result.score < 80) {
            this.meterBar.style.backgroundColor = '#ffeb3b'; // Yellow
        } else {
            this.meterBar.style.backgroundColor = '#4caf50'; // Green
        }

        // Update label
        if (this.strengthLabel) {
            this.strengthLabel.textContent = result.label;
        }

        // Update feedback
        if (this.feedbackList && result.feedback.length > 0) {
            this.feedbackList.innerHTML = result.feedback.map(item => `<li>${item}</li>`).join('');
        } else if (this.feedbackList) {
            this.feedbackList.innerHTML = '<li>Password looks good!</li>';
        }
    }

    getScore() {
        const password = this.passwordInput.value;
        return this.calculateStrength(password).score;
    }

    isStrongEnough(minScore = 40) {
        return this.getScore() >= minScore;
    }
}

// Auto-initialize if default selectors exist
document.addEventListener('DOMContentLoaded', () => {
    const meter = new PasswordStrengthMeter(
        '#password',
        '#password-strength-meter',
        '#password-strength-label',
        '#password-strength-feedback'
    );

    // Expose to global scope for form validation
    window.passwordStrengthMeter = meter;
});
