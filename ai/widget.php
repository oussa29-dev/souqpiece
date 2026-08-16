<?php
// AI shopping assistant widget. Include from partie/footer.php only.
// Renders nothing at all if the assistant is disabled (kill switch), UNLESS
// this browser session has the private-preview flag - set once by visiting
// any page with ?ai=1, so the site owner can test on production before
// flipping 'enabled' on for every visitor. chat.php enforces the same rule.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_GET['ai']) && $_GET['ai'] === '1') {
    $_SESSION['ai_preview'] = true;
}

$__ai_config = require __DIR__ . '/config.php';
if (empty($__ai_config['enabled']) && empty($_SESSION['ai_preview'])) {
    return;
}
?>
<div id="ai-chat-root">
    <button id="ai-chat-toggle" aria-label="مساعد سوقبيس الذكي" type="button">
        <i class="fas fa-comment-dots"></i>
    </button>

    <div id="ai-chat-panel" hidden>
        <div id="ai-chat-header">
            <span><i class="fas fa-robot"></i> مساعد سوقبيس</span>
            <button id="ai-chat-close" aria-label="إغلاق" type="button"><i class="fas fa-times"></i></button>
        </div>
        <div id="ai-chat-messages"></div>
        <div id="ai-chat-lang-select" hidden>
            <i class="fas fa-microphone"></i>
            <button type="button" data-lang="ar-DZ" class="active">AR</button>
            <button type="button" data-lang="fr-FR">FR</button>
            <button type="button" data-lang="en-US">EN</button>
        </div>
        <form id="ai-chat-form" autocomplete="off">
            <button type="button" id="ai-chat-mic" aria-label="إدخال صوتي" hidden><i class="fas fa-microphone"></i></button>
            <input type="text" id="ai-chat-input" placeholder="اكتب سؤالك…" maxlength="1000">
            <button type="submit" id="ai-chat-send" aria-label="إرسال"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<style>
#ai-chat-root, #ai-chat-root * { box-sizing: border-box; }

#ai-chat-toggle {
    position: fixed; bottom: 22px; inset-inline-end: 22px; z-index: 9998;
    width: 58px; height: 58px; border-radius: 50%; border: none;
    background: #e12929; color: #fff; font-size: 24px; cursor: pointer;
    box-shadow: 0 4px 16px rgba(225,41,41,.4);
    display: flex; align-items: center; justify-content: center;
    transition: transform .15s ease;
}
#ai-chat-toggle:hover { transform: scale(1.06); }

#ai-chat-panel {
    position: fixed; bottom: 92px; inset-inline-end: 22px; z-index: 9999;
    width: min(380px, calc(100vw - 32px)); height: min(560px, calc(100vh - 140px));
    background: #fff; border-radius: 14px; overflow: hidden;
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
    display: flex; flex-direction: column;
    font-family: "Hind Siliguri", sans-serif;
}
/* #ai-chat-panel's own `display: flex` above has higher specificity than
   the UA stylesheet's [hidden] rule and would silently defeat it - this
   attribute-selector rule restores it. */
#ai-chat-panel[hidden] { display: none; }

#ai-chat-header {
    background: #e12929; color: #fff; padding: 14px 16px;
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 600; font-size: 15px;
}
#ai-chat-header i.fa-robot { margin-inline-end: 6px; }
#ai-chat-close {
    background: none; border: none; color: #fff; font-size: 16px;
    cursor: pointer; opacity: .85; padding: 4px;
}
#ai-chat-close:hover { opacity: 1; }

#ai-chat-messages {
    flex: 1; overflow-y: auto; padding: 14px; background: #faf9f9;
    display: flex; flex-direction: column; gap: 10px;
}
.ai-msg {
    max-width: 88%; padding: 10px 13px; border-radius: 12px;
    font-size: 14px; line-height: 1.6; white-space: pre-wrap; word-break: break-word;
}
.ai-msg-user {
    align-self: flex-end; background: #e12929; color: #fff;
    border-bottom-right-radius: 3px;
}
.ai-msg-assistant {
    align-self: flex-start; background: #efefef; color: #1a1a1a;
    border-bottom-left-radius: 3px;
}
.ai-msg a { color: #e12929; font-weight: 600; text-decoration: underline; }
.ai-msg-user a { color: #ffe3e3; }
.ai-msg-pending { opacity: .6; font-style: italic; }

#ai-chat-form {
    display: flex; gap: 8px; padding: 10px; border-top: 1px solid #eee; background: #fff;
}
#ai-chat-input {
    flex: 1; border: 1px solid #ddd; border-radius: 20px; padding: 10px 14px;
    font-size: 14px; font-family: inherit; outline: none;
}
#ai-chat-input:focus { border-color: #e12929; }
#ai-chat-send {
    width: 40px; height: 40px; border-radius: 50%; border: none;
    background: #e12929; color: #fff; cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
#ai-chat-send:disabled { opacity: .5; cursor: default; }

/* Aligned under the mic button (not flex-end) and prefixed with a mic
   icon, so it reads as "language for the mic" rather than a general
   chat setting - it has no effect on typed messages. */
#ai-chat-lang-select {
    display: flex; align-items: center; gap: 6px; padding: 0 10px 8px; justify-content: flex-start;
}
#ai-chat-lang-select i { color: #999; font-size: 12px; }
#ai-chat-lang-select button {
    border: 1px solid #ddd; background: #fff; color: #555; border-radius: 12px;
    font-size: 11px; font-weight: 600; padding: 3px 10px; cursor: pointer;
    font-family: inherit;
}
#ai-chat-lang-select button.active { background: #e12929; color: #fff; border-color: #e12929; }

#ai-chat-mic {
    width: 40px; height: 40px; border-radius: 50%; border: none; flex-shrink: 0;
    background: #efefef; color: #555; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s ease, color .15s ease;
}
#ai-chat-mic:hover { background: #e2e2e2; }
#ai-chat-mic.ai-mic-active {
    background: #e12929; color: #fff;
    animation: ai-mic-pulse 1.2s ease-in-out infinite;
}
@keyframes ai-mic-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(225,41,41,.5); }
    50% { box-shadow: 0 0 0 8px rgba(225,41,41,0); }
}

@media (max-width: 480px) {
    #ai-chat-panel { inset-inline-end: 16px; bottom: 84px; }
    #ai-chat-toggle { bottom: 16px; inset-inline-end: 16px; }
}
</style>

<script>
(function () {
    var root = document.getElementById('ai-chat-root');
    var toggle = document.getElementById('ai-chat-toggle');
    var panel = document.getElementById('ai-chat-panel');
    var closeBtn = document.getElementById('ai-chat-close');
    var messages = document.getElementById('ai-chat-messages');
    var form = document.getElementById('ai-chat-form');
    var input = document.getElementById('ai-chat-input');
    var sendBtn = document.getElementById('ai-chat-send');
    var micBtn = document.getElementById('ai-chat-mic');
    var langSelect = document.getElementById('ai-chat-lang-select');
    var opened = false;

    toggle.addEventListener('click', function () {
        opened = !opened;
        panel.hidden = !opened;
        if (opened) input.focus();
    });
    closeBtn.addEventListener('click', function () {
        opened = false;
        panel.hidden = true;
    });

    // Renders assistant/user text as plain text nodes, EXCEPT for
    // "[label](url)" links, which become real <a> elements built via safe
    // DOM APIs (createElement + textContent), never via innerHTML. url is
    // additionally restricted to this site's own relative product links -
    // defense in depth in case a model response were ever manipulated.
    var LINK_RE = /\[([^\]]+)\]\((produit\.php\?[^)\s]+)\)/g;
    function renderMessageText(container, text) {
        var lastIndex = 0;
        var match;
        LINK_RE.lastIndex = 0;
        while ((match = LINK_RE.exec(text)) !== null) {
            if (match.index > lastIndex) {
                container.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }
            var a = document.createElement('a');
            a.textContent = match[1];
            a.href = match[2];
            container.appendChild(a);
            lastIndex = LINK_RE.lastIndex;
        }
        if (lastIndex < text.length) {
            container.appendChild(document.createTextNode(text.slice(lastIndex)));
        }
    }

    function addMessage(role, text) {
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + role;
        div.dir = 'auto';
        renderMessageText(div, text);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;

        addMessage('user', text);
        input.value = '';
        input.disabled = true;
        sendBtn.disabled = true;

        var pending = document.createElement('div');
        pending.className = 'ai-msg ai-msg-assistant ai-msg-pending';
        pending.dir = 'auto';
        pending.textContent = '…';
        messages.appendChild(pending);
        messages.scrollTop = messages.scrollHeight;

        fetch('ai/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            pending.remove();
            if (data.ok) {
                addMessage('assistant', data.reply);
            } else {
                // Server-side error strings (chat.php) are already bilingual
                // AR/FR, unlike the model's own replies which mirror
                // whichever language the customer used - do not wrap them
                // in an Arabic-only prefix here, that reintroduces the same
                // language-mixing bug this was fixed for.
                addMessage('assistant', data.error || 'خطأ غير معروف. / Erreur inconnue.');
            }
        })
        .catch(function () {
            pending.remove();
            addMessage('assistant', 'تعذر الاتصال بالمساعد. / Impossible de contacter l\'assistant.');
        })
        .finally(function () {
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        });
    });

    // Voice input - browser-native Web Speech API only. No audio ever
    // leaves the browser and there is no separate pipeline: the recognized
    // text is written into the SAME #ai-chat-input the user would have
    // typed into, then goes through the existing form submit handler above
    // untouched. The API has no true multi-language auto-detect (a hard
    // platform limitation, not a design choice here), so a small language
    // selector lets the user pick which locale to recognize against.
    var SpeechRecognitionImpl = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognitionImpl) {
        var recognition = new SpeechRecognitionImpl();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.lang = 'ar-DZ';
        var recognizing = false;

        micBtn.hidden = false;
        langSelect.hidden = false;

        langSelect.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-lang]');
            if (!btn) return;
            recognition.lang = btn.dataset.lang;
            langSelect.querySelectorAll('button').forEach(function (b) {
                b.classList.toggle('active', b === btn);
            });
        });

        micBtn.addEventListener('click', function () {
            if (recognizing) {
                recognition.stop();
                return;
            }
            try {
                recognition.start();
            } catch (e) {
                // start() throws if a session is already starting/running
                // (race on rapid double-click) - safe to ignore.
            }
        });

        recognition.onstart = function () {
            recognizing = true;
            micBtn.classList.add('ai-mic-active');
        };
        recognition.onend = function () {
            recognizing = false;
            micBtn.classList.remove('ai-mic-active');
        };
        recognition.onresult = function (event) {
            var transcript = event.results[0][0].transcript;
            // Fill the existing input exactly as if the user had typed it -
            // do not auto-submit. Recognition accuracy varies for
            // code-switched Arabic/French/Darija speech, so the user gets
            // a chance to review/correct before it reaches the model.
            input.value = transcript;
            input.focus();
        };
        recognition.onerror = function (event) {
            recognizing = false;
            micBtn.classList.remove('ai-mic-active');
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                addMessage('assistant', 'يرجى السماح بالوصول للميكروفون من إعدادات المتصفح. / Veuillez autoriser l\'accès au microphone dans les paramètres du navigateur.');
            } else if (event.error !== 'aborted' && event.error !== 'no-speech') {
                addMessage('assistant', 'تعذر التعرف على الصوت، حاول مجدداً أو اكتب سؤالك. / Erreur de reconnaissance vocale, réessayez ou écrivez votre question.');
            }
        };
    }
    // If unsupported (older Firefox, etc.), micBtn/langSelect simply stay
    // hidden - typing still works exactly as before, nothing else changes.
})();
</script>
