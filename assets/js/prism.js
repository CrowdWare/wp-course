/**
 * Prism.js - Lightweight syntax highlighting
 * Basic implementation for WP LMS Plugin
 */

(function() {
    'use strict';

    // Simple Prism implementation
    window.Prism = {
        languages: {},
        
        // Highlight all code blocks
        highlightAll: function() {
            var codeBlocks = document.querySelectorAll('code[class*="language-"], pre[class*="language-"]');
            for (var i = 0; i < codeBlocks.length; i++) {
                this.highlightElement(codeBlocks[i]);
            }
        },
        
        // Highlight a single element
        highlightElement: function(element) {
            var language = this.getLanguage(element);
            if (language && this.languages[language]) {
                var code = element.textContent;
                // Highlight without encoding (encoding happens in tokenizer)
                var highlighted = this.highlight(code, this.languages[language]);
                element.innerHTML = highlighted;
            }
        },
        
        // Get language from class name
        getLanguage: function(element) {
            var className = element.className;
            var match = className.match(/language-(\w+)/);
            return match ? match[1] : null;
        },
        
        // Basic syntax highlighting
        highlight: function(code, grammar) {
            var tokens = this.tokenize(code, grammar);
            return this.stringify(tokens);
        },
        
        // Simple tokenizer
        tokenize: function(code, grammar) {
            var html = code;
            
            // Apply each token type in order
            for (var tokenType in grammar) {
                if (grammar.hasOwnProperty(tokenType)) {
                    var pattern = grammar[tokenType];
                    var regex = new RegExp(pattern.source, pattern.flags || 'g');
                    
                    html = html.replace(regex, function(match) {
                        return '<span class="token ' + tokenType + '">' + match + '</span>';
                    });
                }
            }
            
            return html;
        },
        
        // Convert tokens to HTML (simplified)
        stringify: function(html) {
            return html;
        },
        
        // HTML encode
        encode: function(text) {
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    };
    
    // Language definitions
    Prism.languages.kotlin = {
        'comment': {
            source: '//.*|/\\*[\\s\\S]*?\\*/',
            flags: 'g'
        },
        'string': {
            source: '"(?:[^"\\\\]|\\\\.)*"',
            flags: 'g'
        },
        'keyword': {
            source: '\\b(?:abstract|actual|annotation|as|break|by|catch|class|companion|const|constructor|continue|crossinline|data|do|dynamic|else|enum|expect|external|final|finally|for|fun|get|if|import|in|infix|init|inline|inner|interface|internal|is|lateinit|noinline|null|object|open|operator|out|override|package|private|protected|public|reified|return|sealed|set|super|suspend|tailrec|this|throw|try|typealias|val|var|vararg|when|where|while)\\b',
            flags: 'g'
        },
        'function': {
            source: '\\b\\w+(?=\\s*\\()',
            flags: 'g'
        },
        'number': {
            source: '\\b\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?[fFdD]?\\b',
            flags: 'g'
        },
        'operator': {
            source: '[+\\-*/%=!<>&|^~?:]',
            flags: 'g'
        }
    };
    
    Prism.languages.java = {
        'comment': {
            source: '//.*|/\\*[\\s\\S]*?\\*/',
            flags: 'g'
        },
        'string': {
            source: '"(?:[^"\\\\]|\\\\.)*"',
            flags: 'g'
        },
        'keyword': {
            source: '\\b(?:abstract|assert|boolean|break|byte|case|catch|char|class|const|continue|default|do|double|else|enum|extends|final|finally|float|for|goto|if|implements|import|instanceof|int|interface|long|native|new|package|private|protected|public|return|short|static|strictfp|super|switch|synchronized|this|throw|throws|transient|try|void|volatile|while)\\b',
            flags: 'g'
        },
        'function': {
            source: '\\b\\w+(?=\\s*\\()',
            flags: 'g'
        },
        'number': {
            source: '\\b\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?[fFdDlL]?\\b',
            flags: 'g'
        },
        'operator': {
            source: '[+\\-*/%=!<>&|^~?:]',
            flags: 'g'
        }
    };
    
    Prism.languages.javascript = {
        'comment': {
            source: '//.*|/\\*[\\s\\S]*?\\*/',
            flags: 'g'
        },
        'string': {
            source: '(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|`(?:[^`\\\\]|\\\\.)*`)',
            flags: 'g'
        },
        'keyword': {
            source: '\\b(?:as|async|await|break|case|catch|class|const|continue|debugger|default|delete|do|else|enum|export|extends|finally|for|from|function|get|if|implements|import|in|instanceof|interface|let|new|null|of|package|private|protected|public|return|set|static|super|switch|this|throw|try|typeof|undefined|var|void|while|with|yield)\\b',
            flags: 'g'
        },
        'function': {
            source: '\\b\\w+(?=\\s*\\()',
            flags: 'g'
        },
        'number': {
            source: '\\b(?:0[xX][\\da-fA-F]+|0[bB][01]+|0[oO][0-7]+|\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?)\\b',
            flags: 'g'
        },
        'operator': {
            source: '[+\\-*/%=!<>&|^~?:.]',
            flags: 'g'
        }
    };
    
    Prism.languages.python = {
        'comment': {
            source: '#.*',
            flags: 'g'
        },
        'string': {
            source: '(?:"""[\\s\\S]*?"""|\'\'\'[\\s\\S]*?\'\'\'|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')',
            flags: 'g'
        },
        'keyword': {
            source: '\\b(?:and|as|assert|break|class|continue|def|del|elif|else|except|exec|finally|for|from|global|if|import|in|is|lambda|not|or|pass|print|raise|return|try|while|with|yield)\\b',
            flags: 'g'
        },
        'function': {
            source: '\\b\\w+(?=\\s*\\()',
            flags: 'g'
        },
        'number': {
            source: '\\b(?:0[xX][\\da-fA-F]+|0[bB][01]+|0[oO][0-7]+|\\d+(?:\\.\\d+)?(?:[eE][+-]?\\d+)?)\\b',
            flags: 'g'
        },
        'operator': {
            source: '[+\\-*/%=!<>&|^~]',
            flags: 'g'
        }
    };
    
    // Auto-highlight on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            Prism.highlightAll();
        });
    } else {
        Prism.highlightAll();
    }
    
})();
