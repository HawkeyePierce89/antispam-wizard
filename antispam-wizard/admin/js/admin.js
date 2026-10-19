/**
 * AntiSpam Wizard admin JavaScript.
 *
 * Handles AJAX add/remove for stop-words.
 *
 * @package AntiSpamWizard
 */

(function () {
    'use strict';

    var addButton = document.getElementById('asw-add-word');
    var input = document.getElementById('asw-new-word');
    var wordsList = document.getElementById('asw-words-list');
    var status = document.getElementById('asw-add-status');

    if (!addButton || !input || !wordsList) {
        return;
    }

    function showStatus(message, isError) {
        if (!status) return;
        status.textContent = message;
        status.className = 'asw-status ' + (isError ? 'error' : 'success');
        setTimeout(function () {
            status.textContent = '';
            status.className = 'asw-status';
        }, 3000);
    }

    function createWordTag(word) {
        var isExact = word.length > 0 && word.charAt(0) === '=';
        var tag = document.createElement('span');
        tag.className = 'asw-word-tag asw-pill' + (isExact ? ' asw-pill--exact' : '');
        tag.setAttribute('data-word', word);
        tag.textContent = word;

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'asw-remove-word asw-pill-remove';
        removeBtn.title = aswAdmin.remove_text || 'Remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', function () {
            removeWord(word, tag);
        });

        tag.appendChild(removeBtn);
        return tag;
    }

    function addWord() {
        var word = input.value.trim();
        if (!word) {
            return;
        }

        var data = new FormData();
        data.append('action', 'asw_add_stop_word');
        data.append('nonce', aswAdmin.nonce);
        data.append('word', word);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', aswAdmin.ajax_url);
        xhr.onload = function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                showStatus(aswAdmin.error_text || 'Error', true);
                return;
            }
            if (response.success) {
                var noWords = wordsList.querySelector('.asw-no-words');
                if (noWords) {
                    noWords.remove();
                }
                wordsList.appendChild(createWordTag(word));
                input.value = '';
                showStatus(response.data.message || 'Added', false);
            } else {
                showStatus(response.data.message || 'Error', true);
            }
        };
        xhr.onerror = function () {
            showStatus(aswAdmin.error_text || 'Error', true);
        };
        xhr.send(data);
    }

    function removeWord(word, tagElement) {
        var data = new FormData();
        data.append('action', 'asw_remove_stop_word');
        data.append('nonce', aswAdmin.nonce);
        data.append('word', word);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', aswAdmin.ajax_url);
        xhr.onload = function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                showStatus(aswAdmin.error_text || 'Error', true);
                return;
            }
            if (response.success) {
                tagElement.remove();
                if (!wordsList.querySelector('.asw-word-tag')) {
                    var p = document.createElement('p');
                    p.className = 'asw-no-words';
                    p.textContent = aswAdmin.no_words_text || 'No stop-words configured.';
                    wordsList.appendChild(p);
                }
            } else {
                showStatus((response.data && response.data.message) || aswAdmin.error_text || 'Error', true);
            }
        };
        xhr.onerror = function () {
            showStatus(aswAdmin.error_text || 'Error', true);
        };
        xhr.send(data);
    }

    addButton.addEventListener('click', addWord);

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addWord();
        }
    });

    var removeBtns = wordsList.querySelectorAll('.asw-remove-word');
    for (var i = 0; i < removeBtns.length; i++) {
        (function (btn) {
            var tag = btn.parentElement;
            var word = tag.getAttribute('data-word');
            btn.addEventListener('click', function () {
                removeWord(word, tag);
            });
        })(removeBtns[i]);
    }
})();

/**
 * Handles AJAX add/remove for excluded tokens.
 */
(function () {
    'use strict';

    var addButton = document.getElementById('asw-add-token');
    var input = document.getElementById('asw-new-token');
    var tokensList = document.getElementById('asw-tokens-list');
    var status = document.getElementById('asw-token-status');

    if (!addButton || !input || !tokensList) {
        return;
    }

    function showStatus(message, isError) {
        if (!status) return;
        status.textContent = message;
        status.className = 'asw-status ' + (isError ? 'error' : 'success');
        setTimeout(function () {
            status.textContent = '';
            status.className = 'asw-status';
        }, 3000);
    }

    function createTokenTag(token) {
        var tag = document.createElement('span');
        tag.className = 'asw-word-tag asw-pill asw-pill--token';
        tag.setAttribute('data-word', token);
        tag.textContent = token;

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'asw-remove-token asw-pill-remove';
        removeBtn.title = aswAdmin.remove_text || 'Remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', function () {
            removeToken(token, tag);
        });

        tag.appendChild(removeBtn);
        return tag;
    }

    function addToken() {
        var token = input.value.trim().toLowerCase();
        if (!token) {
            return;
        }

        var data = new FormData();
        data.append('action', 'asw_add_excluded_token');
        data.append('nonce', aswAdmin.excluded_tokens_nonce);
        data.append('token', token);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', aswAdmin.ajax_url);
        xhr.onload = function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                showStatus(aswAdmin.error_text || 'Error', true);
                return;
            }
            if (response.success) {
                var noTokens = tokensList.querySelector('.asw-no-tokens');
                if (noTokens) {
                    noTokens.remove();
                }
                tokensList.appendChild(createTokenTag(token));
                input.value = '';
                showStatus(response.data.message || 'Added', false);
            } else {
                showStatus(response.data.message || 'Error', true);
            }
        };
        xhr.onerror = function () {
            showStatus(aswAdmin.error_text || 'Error', true);
        };
        xhr.send(data);
    }

    function removeToken(token, tagElement) {
        var data = new FormData();
        data.append('action', 'asw_remove_excluded_token');
        data.append('nonce', aswAdmin.excluded_tokens_nonce);
        data.append('token', token);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', aswAdmin.ajax_url);
        xhr.onload = function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                showStatus(aswAdmin.error_text || 'Error', true);
                return;
            }
            if (response.success) {
                tagElement.remove();
                if (!tokensList.querySelector('.asw-word-tag')) {
                    var p = document.createElement('p');
                    p.className = 'asw-no-tokens';
                    p.textContent = aswAdmin.no_tokens_text || 'No excluded tokens configured.';
                    tokensList.appendChild(p);
                }
            } else {
                showStatus((response.data && response.data.message) || aswAdmin.error_text || 'Error', true);
            }
        };
        xhr.onerror = function () {
            showStatus(aswAdmin.error_text || 'Error', true);
        };
        xhr.send(data);
    }

    addButton.addEventListener('click', addToken);

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addToken();
        }
    });

    var removeBtns = tokensList.querySelectorAll('.asw-remove-token');
    for (var i = 0; i < removeBtns.length; i++) {
        (function (btn) {
            var tag = btn.parentElement;
            var token = tag.getAttribute('data-word');
            btn.addEventListener('click', function () {
                removeToken(token, tag);
            });
        })(removeBtns[i]);
    }
})();

/**
 * Mark comment as spam or ham for Bayesian training.
 */
(function () {
    'use strict';

    function showNotice(anchor, message) {
        var container = anchor.closest('.asw-log-actions') || anchor.parentElement;
        if (!container || !container.parentElement) {
            return;
        }
        var existing = container.querySelector('.asw-notice');
        if (existing) {
            existing.remove();
        }
        var notice = document.createElement('span');
        notice.className = 'asw-notice asw-notice--error';
        notice.textContent = message;
        container.appendChild(notice);
        setTimeout(function () {
            if (notice.parentElement) {
                notice.remove();
            }
        }, 4000);
    }

    function markComment(commentId, category, button) {
        var actions = button.closest('.asw-log-actions') || button.parentElement;
        var siblings = actions ? actions.querySelectorAll('button') : [button];
        for (var s = 0; s < siblings.length; s++) {
            siblings[s].disabled = true;
        }

        var data = new FormData();
        data.append('action', 'asw_mark_comment');
        data.append('nonce', aswAdmin.mark_nonce);
        data.append('comment_id', commentId);
        data.append('category', category);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', aswAdmin.ajax_url);
        xhr.onload = function () {
            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                for (var r = 0; r < siblings.length; r++) { siblings[r].disabled = false; }
                showNotice(button, aswAdmin.error_text || 'Invalid response');
                return;
            }
            if (response.success) {
                var pill = document.createElement('span');
                pill.className = 'asw-pill asw-pill--trained';
                pill.textContent = (aswAdmin.trained_as_text || 'Trained as ') + category;
                actions.innerHTML = '';
                actions.appendChild(pill);
                if (response.data && response.data.script_label) {
                    var scriptPill = document.createElement('span');
                    scriptPill.className = 'asw-pill asw-pill--script';
                    scriptPill.textContent = response.data.script_label;
                    actions.appendChild(scriptPill);
                }
            } else {
                for (var r = 0; r < siblings.length; r++) { siblings[r].disabled = false; }
                var msg = (response.data && response.data.message) || (aswAdmin.error_text || 'Error');
                showNotice(button, msg);
            }
        };
        xhr.onerror = function () {
            for (var r = 0; r < siblings.length; r++) { siblings[r].disabled = false; }
            showNotice(button, aswAdmin.error_text || 'Network error');
        };
        xhr.send(data);
    }

    var hamBtns = document.querySelectorAll('.asw-mark-ham');
    var spamBtns = document.querySelectorAll('.asw-mark-spam');

    for (var i = 0; i < hamBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                markComment(btn.getAttribute('data-comment-id'), 'ham', btn);
            });
        })(hamBtns[i]);
    }

    for (var j = 0; j < spamBtns.length; j++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                markComment(btn.getAttribute('data-comment-id'), 'spam', btn);
            });
        })(spamBtns[j]);
    }
})();

/**
 * Section collapse + slider value sync.
 */
(function () {
    'use strict';

    // Collapsible section headers.
    var headers = document.querySelectorAll('[data-asw-collapsible]');
    for (var i = 0; i < headers.length; i++) {
        (function (header) {
            header.addEventListener('click', function () {
                var section = header.closest('.asw-section');
                if (!section) return;
                var collapsed = section.classList.toggle('is-collapsed');
                header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        })(headers[i]);
    }

    // Range sliders mirroring their value into a sibling readout.
    var sliders = document.querySelectorAll('[data-asw-slider]');
    for (var k = 0; k < sliders.length; k++) {
        (function (slider) {
            var wrap = slider.closest('.asw-slider');
            if (!wrap) return;
            var readout = wrap.querySelector('[data-asw-slider-value]');
            if (!readout) return;
            var update = function () {
                var v = parseFloat(slider.value);
                readout.textContent = isNaN(v) ? slider.value : v.toFixed(2);
            };
            slider.addEventListener('input', update);
            update();
        })(sliders[k]);
    }
})();
