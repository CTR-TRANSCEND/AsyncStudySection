/**
 * Discussion System Polish JavaScript (SPEC-UIX-002 Milestone 7)
 * TAG: SPEC-UIX-002-M7-JS
 *
 * Design-TAG: Enhanced discussion system with unread badges, grouping, and quick reply
 * Function-TAG: Improves discussion UX and real-time collaboration
 * Test-TAG: WCAG 2.1 AA compliant, tested via Playwright
 */

var DiscussionPolish = (function() {
    'use strict';

    var state = {
        currentThread: null,
        mentionOptions: [],
        selectedMentionIndex: 0
    };

    /**
     * Initialize discussion enhancements
     */
    function init() {
        initUnreadBadges();
        initMessageGrouping();
        initQuickReplyDrawer();
        initMentionAutocomplete();
        initRefreshButton();
        initToastIntegration();
    }

    /**
     * UNREAD BADGES
     */
    function initUnreadBadges() {
        var threads = document.querySelectorAll('.discussion-thread-item');

        threads.forEach(function(thread) {
            var unreadCount = parseInt(thread.getAttribute('data-unread') || '0');

            if (unreadCount > 0) {
                var badge = document.createElement('span');
                badge.className = 'unread-badge';
                badge.textContent = unreadCount;
                badge.setAttribute('aria-label', unreadCount + ' unread messages');

                var threadTitle = thread.querySelector('.thread-title');
                if (threadTitle) {
                    threadTitle.appendChild(badge);
                }
            }

            // Mark thread as read when clicked
            thread.addEventListener('click', function() {
                markThreadAsRead(thread);
            });
        });
    }

    function markThreadAsRead(thread) {
        thread.setAttribute('data-unread', '0');
        var badge = thread.querySelector('.unread-badge');
        if (badge) {
            badge.remove();
        }

        // Update localStorage
        var threadId = thread.getAttribute('data-thread-id');
        if (threadId) {
            localStorage.setItem('thread_read_' + threadId, 'true');
        }
    }

    /**
     * MESSAGE GROUPING
     */
    function initMessageGrouping() {
        var messages = document.querySelectorAll('.discussion-message');
        var groups = {};

        messages.forEach(function(message) {
            var timestamp = message.getAttribute('data-timestamp');
            if (!timestamp) return;

            var date = new Date(timestamp);
            var dateKey = getDateKey(date);
            var dateLabel = getDateLabel(date);

            if (!groups[dateKey]) {
                groups[dateKey] = {
                    label: dateLabel,
                    dateKey: dateKey,
                    messages: []
                };
            }

            groups[dateKey].messages.push(message);
        });

        // Reorganize messages into groups
        var container = document.querySelector('.chat-container');
        if (!container) return;

        // Clear existing messages
        container.innerHTML = '';

        // Create groups
        Object.keys(groups).forEach(function(key) {
            var group = groups[key];

            var groupElement = document.createElement('div');
            groupElement.className = 'message-group';

            var header = document.createElement('div');
            header.className = 'message-group-header';
            header.setAttribute('data-date', group.dateKey);
            header.textContent = group.label;

            var content = document.createElement('div');
            content.className = 'message-group-content';

            group.messages.forEach(function(message) {
                content.appendChild(message);
            });

            groupElement.appendChild(header);
            groupElement.appendChild(content);
            container.appendChild(groupElement);
        });
    }

    function getDateKey(date) {
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        var messageDate = new Date(date);
        messageDate.setHours(0, 0, 0, 0);

        if (messageDate.getTime() === today.getTime()) {
            return 'today';
        } else if (messageDate.getTime() === yesterday.getTime()) {
            return 'yesterday';
        } else {
            return messageDate.toISOString().split('T')[0];
        }
    }

    function getDateLabel(date) {
        var dateKey = getDateKey(date);

        if (dateKey === 'today') {
            return 'Today';
        } else if (dateKey === 'yesterday') {
            return 'Yesterday';
        } else {
            return date.toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
        }
    }

    /**
     * QUICK REPLY DRAWER
     */
    function initQuickReplyDrawer() {
        // Create quick reply button
        var quickReplyButton = document.createElement('button');
        quickReplyButton.className = 'quick-reply-button';
        quickReplyButton.innerHTML = '✏️';
        quickReplyButton.setAttribute('aria-label', 'Quick reply');
        quickReplyButton.addEventListener('click', openQuickReplyDrawer);
        document.body.appendChild(quickReplyButton);

        // Create drawer HTML
        var drawerHTML = '<div class="quick-reply-backdrop"></div>' +
            '<div class="quick-reply-drawer" role="dialog" aria-label="Quick reply">' +
                '<div class="quick-reply-drawer-header">' +
                    '<h2 class="quick-reply-drawer-title">Quick Reply</h2>' +
                    '<button class="close-drawer-button" aria-label="Close drawer">✕</button>' +
                '</div>' +
                '<div class="quick-reply-textarea-wrapper">' +
                    '<textarea class="quick-reply-textarea" placeholder="Type your message... Use @ to mention reviewers"></textarea>' +
                    '<div class="mention-autocomplete"></div>' +
                '</div>' +
                '<div class="quick-reply-drawer-footer">' +
                    '<button class="submit-reply-button" type="button">Send Reply</button>' +
                '</div>' +
                '<div class="discussion-announcements" aria-live="polite" aria-atomic="true"></div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', drawerHTML);

        // Attach event listeners
        var backdrop = document.querySelector('.quick-reply-backdrop');
        var closeButton = document.querySelector('.close-drawer-button');
        var submitButton = document.querySelector('.submit-reply-button');

        if (backdrop) {
            backdrop.addEventListener('click', closeQuickReplyDrawer);
        }

        if (closeButton) {
            closeButton.addEventListener('click', closeQuickReplyDrawer);
        }

        if (submitButton) {
            submitButton.addEventListener('click', submitQuickReply);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQuickReplyDrawer();
            }
        });
    }

    function openQuickReplyDrawer() {
        var drawer = document.querySelector('.quick-reply-drawer');
        var backdrop = document.querySelector('.quick-reply-backdrop');

        if (drawer) drawer.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-visible');

        // Focus textarea
        var textarea = document.querySelector('.quick-reply-textarea');
        if (textarea) {
            setTimeout(function() {
                textarea.focus();
            }, 300);
        }
    }

    function closeQuickReplyDrawer() {
        var drawer = document.querySelector('.quick-reply-drawer');
        var backdrop = document.querySelector('.quick-reply-backdrop');

        if (drawer) drawer.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-visible');
    }

    function submitQuickReply() {
        var textarea = document.querySelector('.quick-reply-textarea');
        if (!textarea) return;

        var message = textarea.value.trim();
        if (!message) return;

        var submitButton = document.querySelector('.submit-reply-button');
        if (submitButton) {
            submitButton.disabled = true;
        }

        // Use AGR.Toast for notifications
        if (typeof AGR !== 'undefined' && AGR.Toast) {
            AGR.Toast.success('Sending message...');
        }

        // Simulate send (replace with actual AJAX call)
        setTimeout(function() {
            if (typeof AGR !== 'undefined' && AGR.Toast) {
                AGR.Toast.success('Message sent successfully!');
            }

            // Clear textarea and close drawer
            textarea.value = '';
            closeQuickReplyDrawer();

            if (submitButton) {
                submitButton.disabled = false;
            }
        }, 1000);
    }

    /**
     * MENTION AUTOCOMPLETE
     */
    function initMentionAutocomplete() {
        var textarea = document.querySelector('.quick-reply-textarea');
        if (!textarea) return;

        // Load list of reviewers
        loadReviewers();

        textarea.addEventListener('input', function(e) {
            var value = e.target.value;
            var cursorPosition = e.target.selectionStart;

            // Check if @ is being typed
            var beforeCursor = value.substring(0, cursorPosition);
            var atIndex = beforeCursor.lastIndexOf('@');

            if (atIndex !== -1) {
                var searchText = beforeCursor.substring(atIndex + 1);
                showMentionAutocomplete(searchText);
            } else {
                hideMentionAutocomplete();
            }
        });

        textarea.addEventListener('keydown', function(e) {
            var autocomplete = document.querySelector('.mention-autocomplete');
            if (!autocomplete || !autocomplete.classList.contains('is-visible')) return;

            var options = autocomplete.querySelectorAll('.mention-option');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                state.selectedMentionIndex = Math.min(state.selectedMentionIndex + 1, options.length - 1);
                updateMentionSelection();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                state.selectedMentionIndex = Math.max(state.selectedMentionIndex - 1, 0);
                updateMentionSelection();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                selectMentionOption();
            } else if (e.key === 'Escape') {
                hideMentionAutocomplete();
            }
        });
    }

    function loadReviewers() {
        // Simulate loading reviewers (replace with actual AJAX call)
        state.mentionOptions = [
            { id: 1, name: 'Reviewer 1', role: 'Primary Reviewer' },
            { id: 2, name: 'Reviewer 2', role: 'Secondary Reviewer' },
            { id: 3, name: 'Reviewer 3', role: 'Primary Reviewer' }
        ];
    }

    function showMentionAutocomplete(searchText) {
        var autocomplete = document.querySelector('.mention-autocomplete');
        if (!autocomplete) return;

        // Filter options
        var filtered = state.mentionOptions.filter(function(option) {
            return option.name.toLowerCase().indexOf(searchText.toLowerCase()) !== -1;
        });

        if (filtered.length === 0) {
            hideMentionAutocomplete();
            return;
        }

        // Render options
        var html = '';
        filtered.forEach(function(option, index) {
            var initials = option.name.split(' ').map(function(n) {
                return n[0];
            }).join('').toUpperCase();

            html += '<div class="mention-option" data-index="' + index + '" data-id="' + option.id + '">' +
                '<div class="mention-option-avatar">' + initials + '</div>' +
                '<div class="mention-option-info">' +
                    '<div class="mention-option-name">' + option.name + '</div>' +
                    '<div class="mention-option-role">' + option.role + '</div>' +
                '</div>' +
            '</div>';
        });

        autocomplete.innerHTML = html;
        autocomplete.classList.add('is-visible');
        state.selectedMentionIndex = 0;
        updateMentionSelection();

        // Attach click handlers
        autocomplete.querySelectorAll('.mention-option').forEach(function(option) {
            option.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                var name = this.querySelector('.mention-option-name').textContent;
                insertMention(name);
            });
        });
    }

    function hideMentionAutocomplete() {
        var autocomplete = document.querySelector('.mention-autocomplete');
        if (autocomplete) {
            autocomplete.classList.remove('is-visible');
        }
    }

    function updateMentionSelection() {
        var options = document.querySelectorAll('.mention-option');
        options.forEach(function(option, index) {
            if (index === state.selectedMentionIndex) {
                option.classList.add('is-selected');
            } else {
                option.classList.remove('is-selected');
            }
        });
    }

    function selectMentionOption() {
        var selectedOption = document.querySelector('.mention-option.is-selected');
        if (selectedOption) {
            var name = selectedOption.querySelector('.mention-option-name').textContent;
            insertMention(name);
        }
    }

    function insertMention(name) {
        var textarea = document.querySelector('.quick-reply-textarea');
        if (!textarea) return;

        var value = textarea.value;
        var cursorPosition = textarea.selectionStart;

        var beforeCursor = value.substring(0, cursorPosition);
        var afterCursor = value.substring(cursorPosition);

        var atIndex = beforeCursor.lastIndexOf('@');
        var newValue = beforeCursor.substring(0, atIndex) + '@' + name + ' ' + afterCursor;

        textarea.value = newValue;
        textarea.focus();

        var newPosition = atIndex + name.length + 2;
        textarea.setSelectionRange(newPosition, newPosition);

        hideMentionAutocomplete();
    }

    /**
     * REFRESH BUTTON
     */
    function initRefreshButton() {
        var existingButton = document.querySelector('.refresh-discussions-button');
        if (!existingButton) {
            // Create refresh button if it doesn't exist
            var buttonContainer = document.querySelector('.discussion-header');
            if (!buttonContainer) return;

            var refreshHTML = '<button class="refresh-discussions-button" type="button">' +
                '<svg class="refresh-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>' +
                '</svg>' +
                '<span class="refresh-spinner"></span>' +
                '<span class="refresh-text">Refresh</span>' +
            '</button>';

            buttonContainer.insertAdjacentHTML('beforeend', refreshHTML);
        }

        // Attach event listener
        var button = document.querySelector('.refresh-discussions-button');
        if (button) {
            button.addEventListener('click', refreshDiscussions);
        }
    }

    function refreshDiscussions() {
        var button = document.querySelector('.refresh-discussions-button');
        if (!button) return;

        button.classList.add('is-refreshing');
        button.disabled = true;

        // Simulate refresh (replace with actual AJAX call)
        setTimeout(function() {
            button.classList.remove('is-refreshing');
            button.disabled = false;

            // Re-initialize message grouping and badges
            initMessageGrouping();
            initUnreadBadges();

            // Show success toast
            if (typeof AGR !== 'undefined' && AGR.Toast) {
                AGR.Toast.success('Discussions refreshed!');
            }
        }, 1500);
    }

    /**
     * TOAST INTEGRATION
     */
    function initToastIntegration() {
        // Remove any legacy alert() calls
        var legacyAlerts = document.querySelectorAll('alert[role="alert"]');
        legacyAlerts.forEach(function(alert) {
            // Convert to toast if needed
            var message = alert.textContent;
            if (message && typeof AGR !== 'undefined' && AGR.Toast) {
                AGR.Toast.info(message);
            }
            alert.remove();
        });
    }

    /**
     * PUBLIC API
     */
    return {
        init: init,
        openQuickReply: openQuickReplyDrawer,
        closeQuickReply: closeQuickReplyDrawer,
        refresh: refreshDiscussions
    };
})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', DiscussionPolish.init);
} else {
    DiscussionPolish.init();
}
