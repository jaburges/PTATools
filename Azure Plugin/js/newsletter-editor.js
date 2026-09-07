/**
 * Newsletter Editor with GrapesJS
 */

(function($) {
    'use strict';

    var editor = null;
    var currentStep = 1;
    var mediaFrame = null;

    /**
     * Clean HTML content - remove any CSS text that leaked into body
     */
    function cleanHtmlContent(html) {
        if (!html) return '';
        
        // Trim whitespace
        html = html.trim();
        
        // If it's a full HTML document, extract body content
        var bodyMatch = html.match(/<body[^>]*>([\s\S]*)<\/body>/i);
        if (bodyMatch) {
            html = bodyMatch[1].trim();
        }
        
        // Also handle if there's DOCTYPE/html but no body tags
        if (html.indexOf('<!DOCTYPE') === 0 || html.indexOf('<html') === 0) {
            var headEnd = html.indexOf('</head>');
            if (headEnd > -1) {
                html = html.substring(headEnd + 7);
            }
            html = html.replace(/<\/?html[^>]*>/gi, '').replace(/<\/?body[^>]*>/gi, '');
            html = html.trim();
        }
        
        // Find first HTML tag position
        var firstTagPos = html.search(/<[a-z]/i);
        
        if (firstTagPos > 0) {
            // There's text before the first HTML tag
            var beforeTag = html.substring(0, firstTagPos);
            
            // Check if it looks like CSS (has { and })
            if (beforeTag.indexOf('{') !== -1 && beforeTag.indexOf('}') !== -1) {
                // Strip everything before first tag
                html = html.substring(firstTagPos);
            }
            // Also check for CSS comments
            else if (beforeTag.indexOf('/*') !== -1) {
                html = html.substring(firstTagPos);
            }
        }
        
        return html;
    }

    /**
     * Remove the `open` attribute from <details> elements during the
     * email-export step. The FAQ blocks are authored with <details open>
     * so the answer is visible while editing in the GrapesJS canvas;
     * collapsed-by-default behaviour is restored at send time so the
     * email arrives looking like an FAQ accordion in clients that
     * support <details> (Apple Mail, Gmail, Outlook web, Yahoo).
     */
    function stripFaqOpenAttr(html) {
        if (!html || typeof html !== 'string') { return html; }
        return html
            .replace(/<details\s+open(\s|>)/gi, '<details$1')
            .replace(/<details([^>]*?)\sopen(=("?open"?|""))?(\s|>)/gi, '<details$1$4');
    }

    /**
     * Get email-ready HTML with CSS properly handled
     * Email clients strip <style> tags, so we need clean HTML
     */
    function getEmailReadyHtml() {
        if (!editor) return '';
        
        applyAllImageLinks();
        hoistEscapedBlocksIntoCanvas();

        // Get HTML and CSS separately from GrapesJS
        var html = editor.getHtml();
        var css = editor.getCss();
        
        // Aggressively clean up the HTML - remove any CSS text that leaked in
        html = cleanHtmlContent(html);
        html = wrapImgHrefInHtml(html);

        // FAQ blocks are authored with <details open> so the answer is
        // visible/editable in the canvas. Strip the `open` attribute on
        // export so the email arrives collapsed-by-default in clients
        // that honour the disclosure triangle. Operates on serialized
        // HTML rather than the live model, so editor state is not
        // mutated.
        html = stripFaqOpenAttr(html);
        html = stripRowGaps(html);
        html = stripSectionHints(html);
        html = stripEmptySections(html);
        
        // Build proper email HTML structure
        var emailHtml = '<!DOCTYPE html>\n';
        emailHtml += '<html>\n<head>\n';
        emailHtml += '<meta charset="UTF-8">\n';
        emailHtml += '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n';
        emailHtml += '<meta http-equiv="X-UA-Compatible" content="IE=edge">\n';
        emailHtml += '<title>Newsletter</title>\n';
        
        // Add CSS in head (better than body, some clients support it)
        emailHtml += '<style type="text/css">\n';
        emailHtml += '/* Email Reset */\n';
        emailHtml += 'body { margin: 0 !important; padding: 0 !important; }\n';
        emailHtml += 'table { border-collapse: collapse !important; }\n';
        emailHtml += 'img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }\n';
        if (css) {
            emailHtml += css + '\n';
        }
        emailHtml += (newsletterEditorConfig.columnGapCss || '') + '\n';
        emailHtml += (newsletterEditorConfig.dividerCss || '') + '\n';
        emailHtml += '/* pta-nl-stack-cols */\n';
        emailHtml += (newsletterEditorConfig.columnStackCss || '') + '\n';
        emailHtml += '</style>\n';
        
        emailHtml += '</head>\n<body style="margin:0;padding:0;">\n';
        emailHtml += html;
        emailHtml += '\n</body>\n</html>';
        
        return emailHtml;
    }

    // Initialize when document is ready
    $(document).ready(function() {
        initWorkflowNavigation();
        initSubjectCharCount();
        initFromFieldSync();
        initEditorHelpBar();
        initSendOptions();
        initPageOptions();
        initRecipientCheckboxes();
        
        // Initialize GrapesJS when editor container exists
        if ($('#gjs-editor').length) {
            // Small delay to ensure libraries are loaded
            setTimeout(initGrapesJS, 100);
        }
    });

    /**
     * Initialize GrapesJS Editor
     */
    function initGrapesJS() {
        // Check if GrapesJS is available
        if (typeof grapesjs === 'undefined') {
            console.error('GrapesJS not loaded');
            $('#gjs-editor').html('<p style="padding:20px;color:#d63638;">Error: GrapesJS library not loaded. Please refresh the page.</p>');
            return;
        }

        try {
            editor = grapesjs.init({
                container: '#gjs-editor',
                fromElement: false,
                height: '100%',
                width: 'auto',
                storageManager: false,
                avoidInlineStyle: false,
                
                // Panels configuration
                panels: { defaults: [] },
                
                // Canvas configuration - Fixed for proper responsive preview
                canvas: {
                    styles: [
                        'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap'
                    ]
                },
                
                // Device manager for responsive preview - Fixed widths
                deviceManager: {
                    devices: [
                        { 
                            name: 'Desktop', 
                            width: ''
                        },
                        { 
                            name: 'Tablet', 
                            width: '768px',
                            widthMedia: '768px'
                        },
                        { 
                            name: 'Mobile', 
                            width: '320px',
                            widthMedia: '480px'
                        },
                    ]
                },
                
                // Plugins
                plugins: ['grapesjs-preset-newsletter'],
                pluginsOpts: {
                    'grapesjs-preset-newsletter': {
                        modalTitleImport: 'Import HTML',
                        modalBtnImport: 'Import',
                        importPlaceholder: '<table>...</table>',
                        updateStyleManager: false,
                        showStylesOnChange: false,
                        showBlocksOnLoad: false,
                        cellStyle: {
                            'font-family': 'Arial, sans-serif',
                            'font-size': '14px',
                            'color': '#333333'
                        }
                    }
                },
                
                // Style manager sectors - Updated to use styles-container
                styleManager: {
                    appendTo: '#styles-container',
                    sectors: [
                        {
                            name: 'Typography',
                            open: true,
                            properties: [
                                {
                                    property: 'font-family',
                                    type: 'select',
                                    default: 'Arial, Helvetica, sans-serif',
                                    options: [
                                        { id: 'Arial, Helvetica, sans-serif', label: 'Arial' },
                                        { id: 'Georgia, serif', label: 'Georgia' },
                                        { id: 'Tahoma, Geneva, sans-serif', label: 'Tahoma' },
                                        { id: "'Times New Roman', Times, serif", label: 'Times New Roman' },
                                        { id: 'Verdana, Geneva, sans-serif', label: 'Verdana' },
                                        { id: "'Trebuchet MS', Helvetica, sans-serif", label: 'Trebuchet MS' },
                                        { id: "'Courier New', Courier, monospace", label: 'Courier New' }
                                    ]
                                },
                                {
                                    property: 'font-size',
                                    type: 'select',
                                    defaults: '14px',
                                    options: [
                                        { id: '10px', label: '10' },
                                        { id: '12px', label: '12' },
                                        { id: '13px', label: '13' },
                                        { id: '14px', label: '14' },
                                        { id: '16px', label: '16' },
                                        { id: '18px', label: '18' },
                                        { id: '20px', label: '20' },
                                        { id: '24px', label: '24' },
                                        { id: '28px', label: '28' },
                                        { id: '32px', label: '32' },
                                        { id: '36px', label: '36' },
                                        { id: '48px', label: '48' }
                                    ]
                                },
                                'font-weight',
                                'letter-spacing',
                                'color',
                                'line-height',
                                'text-align',
                                'text-decoration'
                            ]
                        },
                        {
                            name: 'Spacing',
                            open: false,
                            properties: [
                                'padding',
                                'padding-top',
                                'padding-right',
                                'padding-bottom',
                                'padding-left',
                                'margin',
                                'margin-top',
                                'margin-right',
                                'margin-bottom',
                                'margin-left'
                            ]
                        },
                        {
                            name: 'Background',
                            open: false,
                            properties: [
                                'background-color',
                                'background-image',
                                'background-repeat',
                                'background-position',
                                'background-size'
                            ]
                        },
                        {
                            name: 'Border',
                            open: false,
                            properties: [
                                'border-width',
                                'border-style',
                                'border-color',
                                'border-radius'
                            ]
                        },
                        {
                            name: 'Dimensions',
                            open: false,
                            properties: [
                                'width',
                                'height',
                                'max-width',
                                'min-height'
                            ]
                        }
                    ]
                },
                
                // Trait manager - Right sidebar settings panel
                traitManager: {
                    appendTo: '#traits-container'
                },
                
                // Layer manager
                layerManager: {
                    appendTo: '#layers-panel'
                },
                
                // Block manager
                blockManager: {
                    appendTo: '#blocks-panel'
                },
                
                // Asset manager for WordPress Media Library
                assetManager: {
                    upload: false,
                    uploadFile: function(e) {
                        // Use WordPress Media Library instead
                        openMediaLibrary();
                    },
                    custom: {
                        open: function(props) {
                            openMediaLibrary(props);
                        },
                        close: function() {}
                    }
                }
            });

            editor.on('load', injectColumnStackCss);
            editor.on('canvas:frame:load', injectColumnStackCss);
            editor.on('load', function() {
                window.setTimeout(function() {
                    syncAllEmailButtons();
                    applyAllImageLinks();
                }, 0);
            });
            editor.on('component:update', function(component) {
                if (component && component.changed && Object.prototype.hasOwnProperty.call(component.changed, 'href')) {
                    applyImageLink(component);
                }
            });

            registerComponentTypes();
            addEmailBlocks();
            setupColumnFramework();
            setupStyleApply();
            stripGrapesPanels();
            editor.on('load', stripGrapesPanels);

            // Make sure the inline rich-text toolbar includes a clearly
            // labelled Link action. GrapesJS ships with bold/italic/
            // underline/strikethrough/link by default but the link icon
            // is small and easy to miss; we replace the default with a
            // prompt-driven version that handles both adding and
            // removing links (and supports target=_blank for email-
            // friendly behaviour).
            customizeRichTextEditor();
            
            // Load initial content if available
            loadInitialContent();
            
            // Setup UI controls
            setupDeviceButtons();
            setupToolbarButtons();
            setupSidebarTabs();
            setupComponentSelection();
            
            // Register fallback command for CSS inlining (in case preset doesn't provide it)
            if (!editor.Commands.has('gjs-get-inlined-html')) {
                editor.Commands.add('gjs-get-inlined-html', {
                    run: function(editor) {
                        // Simple fallback - just return HTML with embedded styles
                        var html = editor.getHtml();
                        var css = editor.getCss();
                        return '<style>' + css + '</style>' + html;
                    }
                });
            }
            
            console.log('GrapesJS Newsletter Editor initialized successfully');
            
        } catch (error) {
            console.error('GrapesJS initialization error:', error);
            $('#gjs-editor').html('<p style="padding:20px;color:#d63638;">Error initializing editor: ' + error.message + '</p>');
        }
    }

    /**
     * grapesjs-preset-newsletter resets the panel set to devices,
     * undo/redo/code, and a views header (styles/traits/blocks).
     * Those duplicate our design toolbar and Settings sidebar.
     */
    function stripGrapesPanels() {
        if (!editor || !editor.Panels) {
            return;
        }
        ['commands', 'devices-c', 'options', 'views', 'views-container'].forEach(function(id) {
            try {
                if (editor.Panels.getPanel && editor.Panels.getPanel(id) && editor.Panels.removePanel) {
                    editor.Panels.removePanel(id);
                }
            } catch (e) { /* already gone */ }
        });
    }

    /**
     * Stack 2/3-column tables in the GrapesJS canvas when the
     * Mobile device preview shrinks the iframe below 600px.
     */
    function injectColumnStackCss() {
        if (!editor || !editor.Canvas || !editor.Canvas.getDocument) {
            return;
        }
        var doc = editor.Canvas.getDocument();
        if (!doc || !doc.head) {
            return;
        }
        if (!doc.getElementById('pta-nl-stack-cols')) {
            var style = doc.createElement('style');
            style.id = 'pta-nl-stack-cols';
            style.textContent = newsletterEditorConfig.columnStackCss || '';
            doc.head.appendChild(style);
        }
        if (!doc.getElementById('pta-nl-col-gap')) {
            var gapCss = doc.createElement('style');
            gapCss.id = 'pta-nl-col-gap';
            gapCss.textContent = newsletterEditorConfig.columnGapCss || '';
            doc.head.appendChild(gapCss);
        }
        if (!doc.getElementById('pta-nl-divider')) {
            var dividerCss = doc.createElement('style');
            dividerCss.id = 'pta-nl-divider';
            dividerCss.textContent = newsletterEditorConfig.dividerCss || '';
            doc.head.appendChild(dividerCss);
        }
        if (!doc.getElementById('pta-nl-row-gaps')) {
            var gapStyle = doc.createElement('style');
            gapStyle.id = 'pta-nl-row-gaps';
            gapStyle.textContent = rowGapCanvasCss();
            doc.head.appendChild(gapStyle);
        }
    }

    function rowGapCanvasCss() {
        return [
            '.nl-row-gap{height:32px;margin:4px 0;border:2px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;box-sizing:border-box;position:relative;}',
            '.nl-row-gap::after{content:"Drop here for full width";display:flex;align-items:center;justify-content:center;height:100%;font:12px/1 Arial,Helvetica,sans-serif;color:#8c8f94;}',
            'body.pta-nl-dragging .nl-row-gap{border-color:#2271b1;background:rgba(34,113,177,.10);}',
            'body.pta-nl-dragging .nl-row-gap::after{color:#2271b1;}',
            '.nl-row-gap.gjs-hovered,.nl-row-gap.gjs-selected{border-color:#2271b1;background:rgba(34,113,177,.14);}',
            'table.nl-section{outline:1px dashed #c3c4c7;outline-offset:3px;box-sizing:border-box;}',
            'table.nl-section.gjs-selected,table.nl-section.gjs-hovered{outline-color:#2271b1;}',
            'table.nl-section-empty{height:260px !important;}',
            'table.nl-section-empty td.nl-section-body{height:260px !important;padding:56px 24px !important;vertical-align:middle !important;box-sizing:border-box;}',
            'td.nl-section-body{vertical-align:top;}',
            'td.nl-section-body > .nl-section-hint{display:flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:140px;margin:0 auto;padding:0;border:2px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;font:13px/1.45 Arial,Helvetica,sans-serif;color:#6d7882;}',
            'td.nl-section-body > .nl-section-hint::after{content:"Drop here";}',
            'body.pta-nl-dragging td.nl-section-body > .nl-section-hint{border-color:#2271b1;background:rgba(34,113,177,.10);color:#2271b1;}',
            'td.nl-section-body > .nl-row-gap{display:none;}',
            'body.pta-nl-dragging table.nl-section:not(.nl-section-empty) td.nl-section-body{background:rgba(34,113,177,.06);}',
            'table[width="600"]{width:600px !important;max-width:600px !important;}',
            'table.nl-divider hr,.nl-divider hr{display:block !important;width:100% !important;height:0 !important;margin:0 !important;border:0 !important;border-top:2px solid #dddddd !important;}',
            'table.nl-divider .nl-divider-rule{height:2px !important;line-height:2px !important;font-size:1px !important;background-color:#dddddd !important;border:0 !important;}'
        ].join('');
    }

    function stripSectionHints(html) {
        if (!html || typeof html !== 'string') {
            return html;
        }
        return html.replace(/<(p|div)[^>]*class="[^"]*nl-section-hint[^"]*"[^>]*>[\s\S]*?<\/\1>/gi, '');
    }

    function extractBalancedTable(html, start) {
        if (!html || start < 0) {
            return null;
        }
        var depth = 0;
        var re = /<\/?table\b[^>]*>/gi;
        re.lastIndex = start;
        var m;
        while ((m = re.exec(html))) {
            if (m.index < start) {
                continue;
            }
            if (m[0].charAt(1) === '/') {
                depth--;
                if (depth === 0) {
                    return html.substring(start, m.index + m[0].length);
                }
            } else {
                depth++;
            }
        }
        return null;
    }

    function sectionTableIsEmpty(table) {
        if (!table) {
            return true;
        }
        if (/<hr\b|<img\b|nl-divider|nl-button|nl-now-next|nl-stack-cols/i.test(table)) {
            return false;
        }
        var inner = table
            .replace(/<(p|div)[^>]*class="[^"]*nl-section-hint[^"]*"[^>]*>[\s\S]*?<\/\1>/gi, '')
            .replace(/<[^>]+>/g, '')
            .replace(/&nbsp;/gi, '')
            .replace(/\s+/g, '');
        return !inner;
    }

    function stripEmptySections(html) {
        if (!html || typeof html !== 'string') {
            return html;
        }
        var out = '';
        var i = 0;
        var re = /<table\b[^>]*nl-section[^>]*>/gi;
        var m;
        while ((m = re.exec(html))) {
            var full = extractBalancedTable(html, m.index);
            if (!full) {
                break;
            }
            out += html.substring(i, m.index);
            if (!sectionTableIsEmpty(full)) {
                out += full
                    .replace(/\sclass="([^"]*)nl-section-empty([^"]*)"/gi, ' class="$1$2"')
                    .replace(/\sheight="260"/gi, '')
                    .replace(/height:\s*260px;?\s*/gi, '');
            }
            i = m.index + full.length;
            re.lastIndex = i;
        }
        out += html.substring(i);
        return out;
    }

    function stripRowGaps(html) {
        if (!html || typeof html !== 'string') {
            return html;
        }
        return html
            .replace(/<div[^>]*class="[^"]*nl-row-gap[^"]*"[^>]*>[\s\S]*?<\/div>/gi, '')
            .replace(/<div[^>]*class='[^']*nl-row-gap[^']*'[^>]*>[\s\S]*?<\/div>/gi, '');
    }

    /**
     * Add custom email blocks with modern visual previews
     */
    function addEmailBlocks() {
        if (!editor) return;

        var bm = editor.BlockManager;

        // grapesjs-preset-newsletter registers its own 1/2/3-column
        // sections, text, image, divider, etc. Those sit next to ours
        // as duplicates and the preset columns nest as tables-in-cells.
        // Keep the preset for inlining/email helpers; drop its blocks.
        [
            'sect100', 'sect50', 'sect30', 'sect37',
            'button', 'divider', 'text', 'text-sect',
            'image', 'quote', 'link', 'grid-items', 'list-items'
        ].forEach(function(id) {
            if (bm.get(id)) {
                bm.remove(id);
            }
        });

        // Clean Elementor-style SVG icons
        var c = '#6d7882'; // Icon color
        var icons = {
            // Layout
            section: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="4" y1="18" x2="44" y2="18" stroke="'+c+'" stroke-width="2"/></svg>',
            sectionGroup: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="8" y="6" width="32" height="14" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="8" y="24" width="32" height="18" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="14" y1="31" x2="34" y2="31" stroke="'+c+'" stroke-width="2"/><line x1="14" y1="36" x2="28" y2="36" stroke="'+c+'" stroke-width="2"/></svg>',
            columns2: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="18" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="26" y="8" width="18" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            columns3: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="3" y="8" width="12" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="18" y="8" width="12" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="33" y="8" width="12" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            
            // Content
            text: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><line x1="6" y1="12" x2="42" y2="12" stroke="'+c+'" stroke-width="3" stroke-linecap="round"/><line x1="6" y1="20" x2="36" y2="20" stroke="'+c+'" stroke-width="3" stroke-linecap="round"/><line x1="6" y1="28" x2="42" y2="28" stroke="'+c+'" stroke-width="3" stroke-linecap="round"/><line x1="6" y1="36" x2="26" y2="36" stroke="'+c+'" stroke-width="3" stroke-linecap="round"/></svg>',
            heading: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><text x="8" y="35" font-family="Arial, sans-serif" font-size="28" font-weight="bold" fill="'+c+'">T</text><line x1="28" y1="34" x2="40" y2="34" stroke="'+c+'" stroke-width="2"/></svg>',
            image: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="14" cy="18" r="4" fill="'+c+'"/><polyline points="4,36 16,24 24,32 32,22 44,36" fill="none" stroke="'+c+'" stroke-width="2" stroke-linejoin="round"/></svg>',
            button: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="6" y="16" width="36" height="16" rx="8" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="16" y1="24" x2="32" y2="24" stroke="'+c+'" stroke-width="3" stroke-linecap="round"/></svg>',
            divider: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><line x1="4" y1="24" x2="44" y2="24" stroke="'+c+'" stroke-width="2"/></svg>',
            spacer: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><line x1="24" y1="8" x2="24" y2="40" stroke="'+c+'" stroke-width="2" stroke-dasharray="4 4"/><polyline points="16,14 24,6 32,14" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16,34 24,42 32,34" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            
            // Sections
            header: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><text x="6" y="30" font-family="Arial, sans-serif" font-size="24" font-weight="bold" fill="'+c+'">H</text><line x1="26" y1="18" x2="42" y2="18" stroke="'+c+'" stroke-width="2"/><line x1="26" y1="28" x2="38" y2="28" stroke="'+c+'" stroke-width="2"/></svg>',
            footer: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="28" width="40" height="12" rx="2" fill="'+c+'" opacity="0.15"/><rect x="4" y="28" width="40" height="12" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="12" y1="34" x2="36" y2="34" stroke="'+c+'" stroke-width="2"/><rect x="4" y="8" width="40" height="16" rx="2" fill="none" stroke="'+c+'" stroke-width="2" stroke-dasharray="4 4"/></svg>',
            social: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="12" cy="24" r="6" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="24" cy="24" r="6" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="36" cy="24" r="6" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            
            // Personalization
            user: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="16" r="8" fill="none" stroke="'+c+'" stroke-width="2"/><path d="M8,42 C8,32 16,26 24,26 C32,26 40,32 40,42" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            email: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="10" width="40" height="28" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><polyline points="4,12 24,26 44,12" fill="none" stroke="'+c+'" stroke-width="2" stroke-linejoin="round"/></svg>',
            link: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M20,28 C18,26 18,22 20,20 L26,14 C28,12 32,12 34,14 C36,16 36,20 34,22 L32,24" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round"/><path d="M28,20 C30,22 30,26 28,28 L22,34 C20,36 16,36 14,34 C12,32 12,28 14,26 L16,24" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round"/></svg>',
            browser: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="4" y1="16" x2="44" y2="16" stroke="'+c+'" stroke-width="2"/><circle cx="10" cy="12" r="2" fill="'+c+'"/><circle cx="16" cy="12" r="2" fill="'+c+'"/><circle cx="22" cy="12" r="2" fill="'+c+'"/></svg>',
            
            // Advanced
            html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><text x="6" y="32" font-family="monospace" font-size="14" fill="'+c+'">&lt;/&gt;</text></svg>',
            video: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="10" width="40" height="28" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><polygon points="20,16 20,32 32,24" fill="'+c+'"/></svg>',
            gallery: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="4" width="18" height="18" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="26" y="4" width="18" height="18" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="4" y="26" width="18" height="18" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><rect x="26" y="26" width="18" height="18" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            quote: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><text x="4" y="30" font-family="Georgia, serif" font-size="36" fill="'+c+'">"</text><line x1="22" y1="20" x2="44" y2="20" stroke="'+c+'" stroke-width="2"/><line x1="22" y1="28" x2="38" y2="28" stroke="'+c+'" stroke-width="2"/></svg>',
            list: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="8" cy="12" r="3" fill="'+c+'"/><line x1="16" y1="12" x2="42" y2="12" stroke="'+c+'" stroke-width="2"/><circle cx="8" cy="24" r="3" fill="'+c+'"/><line x1="16" y1="24" x2="42" y2="24" stroke="'+c+'" stroke-width="2"/><circle cx="8" cy="36" r="3" fill="'+c+'"/><line x1="16" y1="36" x2="42" y2="36" stroke="'+c+'" stroke-width="2"/></svg>',
            table: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="4" y1="18" x2="44" y2="18" stroke="'+c+'" stroke-width="2"/><line x1="4" y1="28" x2="44" y2="28" stroke="'+c+'" stroke-width="2"/><line x1="18" y1="8" x2="18" y2="40" stroke="'+c+'" stroke-width="2"/><line x1="32" y1="8" x2="32" y2="40" stroke="'+c+'" stroke-width="2"/></svg>',
            countdown: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="18" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="12" x2="24" y2="24" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="24" x2="32" y2="28" stroke="'+c+'" stroke-width="2"/></svg>',
            map: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path d="M24,6 C16,6 10,12 10,20 C10,32 24,42 24,42 C24,42 38,32 38,20 C38,12 32,6 24,6" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="24" cy="20" r="5" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            
            // WordPress/PTA
            posts: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="6" width="16" height="16" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="10" x2="44" y2="10" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="18" x2="38" y2="18" stroke="'+c+'" stroke-width="2"/><rect x="4" y="26" width="16" height="16" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="30" x2="44" y2="30" stroke="'+c+'" stroke-width="2"/><line x1="24" y1="38" x2="38" y2="38" stroke="'+c+'" stroke-width="2"/></svg>',
            pta: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="16" cy="14" r="6" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="32" cy="14" r="6" fill="none" stroke="'+c+'" stroke-width="2"/><circle cx="24" cy="30" r="6" fill="none" stroke="'+c+'" stroke-width="2"/><path d="M10,26 C10,22 12,20 16,20 C18,20 20,21 21,22" fill="none" stroke="'+c+'" stroke-width="2"/><path d="M38,26 C38,22 36,20 32,20 C30,20 28,21 27,22" fill="none" stroke="'+c+'" stroke-width="2"/><path d="M18,40 C18,38 20,36 24,36 C28,36 30,38 30,40" fill="none" stroke="'+c+'" stroke-width="2"/></svg>',
            shortcode: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><text x="4" y="32" font-family="monospace" font-size="14" fill="'+c+'">[...]</text></svg>',
            faq: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="6" width="40" height="10" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><polyline points="36,10 39,13 42,10" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="20" width="40" height="22" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="27" x2="39" y2="27" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="33" x2="35" y2="33" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="39" x2="30" y2="39" stroke="'+c+'" stroke-width="2"/></svg>',
            calendar: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="6" y="10" width="36" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="6" y1="18" x2="42" y2="18" stroke="'+c+'" stroke-width="2"/><line x1="16" y1="6" x2="16" y2="14" stroke="'+c+'" stroke-width="2" stroke-linecap="round"/><line x1="32" y1="6" x2="32" y2="14" stroke="'+c+'" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="26" x2="22" y2="26" stroke="'+c+'" stroke-width="2"/><line x1="12" y1="34" x2="20" y2="34" stroke="'+c+'" stroke-width="2"/><line x1="28" y1="26" x2="38" y2="26" stroke="'+c+'" stroke-width="2"/><line x1="28" y1="34" x2="36" y2="34" stroke="'+c+'" stroke-width="2"/></svg>'
        };

        // === LAYOUT BLOCKS ===
        // Section is a movable group. 1/2/3 Columns stay single-level
        // inside a section (or at the top of the canvas) — never nest
        // a column row inside another column cell.
        bm.add('section-group', {
            label: 'Section',
            category: 'Layout',
            media: icons.sectionGroup,
            content: sectionGroupHtml()
        });

        bm.add('columns-1', {
            label: '1 Column',
            category: 'Layout',
            media: icons.section,
            content: columnRowHtml(1)
        });

        bm.add('columns-2', {
            label: '2 Columns',
            category: 'Layout',
            media: icons.columns2,
            content: columnRowHtml(2)
        });

        bm.add('columns-3', {
            label: '3 Columns',
            category: 'Layout',
            media: icons.columns3,
            content: columnRowHtml(3)
        });

        // === CONTENT BLOCKS ===
        bm.add('text-block', {
            label: 'Text',
            category: 'Content',
            media: icons.text,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 0; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333;">
                            <p style="margin: 0;">Add your text content here. You can style this text in Settings.</p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('heading', {
            label: 'Heading',
            category: 'Content',
            media: icons.heading,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 0;">
                            <h1 style="margin: 0; font-family: Arial, sans-serif; font-size: 28px; font-weight: bold; color: #1d2327;">
                                Your Heading Here
                            </h1>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('image-block', {
            label: 'Image',
            category: 'Content',
            media: icons.image,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td align="center" style="padding: 10px;">
                            <img src="https://via.placeholder.com/600x300/e0e0e0/666666?text=Click+to+add+image" alt="Image" width="100%" style="display: block; width: 100%; max-width: 100%; height: auto;">
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('button', {
            label: 'Button',
            category: 'Content',
            media: icons.button,
            content: `
                <table class="nl-button" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 15px auto;">
                    <tr>
                        <td align="center" bgcolor="#2271b1" style="border-radius: 4px;">
                            <a href="#" target="_blank" style="display: inline-block; padding: 14px 30px; font-family: Arial, sans-serif; font-size: 16px; font-weight: bold; color: #ffffff; text-decoration: none;">
                                Click Here
                            </a>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('divider', {
            label: 'Divider',
            category: 'Content',
            media: icons.divider,
            content: `
                <table class="nl-divider" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 16px 20px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="nl-divider-rule" height="2" bgcolor="#dddddd" style="height: 2px; line-height: 2px; font-size: 1px; background-color: #dddddd; border: 0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('spacer', {
            label: 'Spacer',
            category: 'Content',
            media: icons.spacer,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="height: 30px; line-height: 30px; font-size: 1px;">&nbsp;</td>
                    </tr>
                </table>
            `
        });

        // FAQ — single expandable Q/A pair built on <details>/<summary>.
        // Email-client behaviour:
        //   - Apple Mail (macOS, iOS, iPadOS), Gmail (web/iOS/Android),
        //     Yahoo, Outlook on the web — the disclosure triangle is
        //     interactive; recipients can collapse/expand each item.
        //   - Outlook desktop (Windows / Mac classic) — does not support
        //     <details>; the answer renders permanently expanded, which
        //     still reads cleanly as a Q/A list.
        // We render the block with `open` so the answer is visible in
        // the GrapesJS canvas (otherwise users would not know there is
        // hidden text to edit). On send, the editor's getEmailReadyHtml()
        // pipeline strips the `open` attribute via stripFaqOpenAttr()
        // so the email arrives collapsed-by-default in supporting
        // clients. Edit-time vs send-time behaviour is decoupled.
        bm.add('faq-item', {
            label: 'FAQ Item',
            category: 'Content',
            media: icons.faq,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 8px 0;" class="pta-faq-block">
                    <tr>
                        <td style="padding: 0 20px;">
                            <details open style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; background: #ffffff; font-family: Arial, Helvetica, sans-serif;">
                                <summary style="cursor: pointer; font-weight: 700; font-size: 16px; color: #1d2327; list-style: none; outline: none;">
                                    Question — type your question here
                                </summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1; font-size: 14px; line-height: 1.6; color: #50575e;">
                                    Answer — type the answer here. You can include <a href="#" style="color:#2271b1;">links</a>, <strong>bold text</strong>, and lists. Some email clients (Outlook desktop) will show this expanded by default, which is fine.
                                </div>
                            </details>
                        </td>
                    </tr>
                </table>
            `
        });

        // FAQ — pre-stacked section with a heading and three Q/A items,
        // ready for the user to swap the placeholder text. Saves the
        // user from dragging FAQ Item three times for the common case.
        bm.add('faq-section', {
            label: 'FAQ Section',
            category: 'Content',
            media: icons.faq,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;" class="pta-faq-block">
                    <tr>
                        <td style="padding: 0 20px;">
                            <h2 style="margin: 0 0 14px 0; font-family: Arial, Helvetica, sans-serif; font-size: 22px; color: #1d2327;">
                                Frequently Asked Questions
                            </h2>
                            <details open style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; background: #ffffff; margin-bottom: 8px; font-family: Arial, Helvetica, sans-serif;">
                                <summary style="cursor: pointer; font-weight: 700; font-size: 16px; color: #1d2327; list-style: none; outline: none;">
                                    What time does the event start?
                                </summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1; font-size: 14px; line-height: 1.6; color: #50575e;">
                                    Doors open at 5:30 PM and the program begins at 6:00 PM. We recommend arriving early to find parking and get settled.
                                </div>
                            </details>
                            <details open style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; background: #ffffff; margin-bottom: 8px; font-family: Arial, Helvetica, sans-serif;">
                                <summary style="cursor: pointer; font-weight: 700; font-size: 16px; color: #1d2327; list-style: none; outline: none;">
                                    Where can I park?
                                </summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1; font-size: 14px; line-height: 1.6; color: #50575e;">
                                    Free parking is available in the school lot. Additional parking can be found on the surrounding streets — please be mindful of neighbours' driveways.
                                </div>
                            </details>
                            <details open style="border: 1px solid #dcdcde; border-radius: 6px; padding: 14px 16px; background: #ffffff; font-family: Arial, Helvetica, sans-serif;">
                                <summary style="cursor: pointer; font-weight: 700; font-size: 16px; color: #1d2327; list-style: none; outline: none;">
                                    How do I volunteer?
                                </summary>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0f0f1; font-size: 14px; line-height: 1.6; color: #50575e;">
                                    Sign up on our volunteer page or reply to this email and we'll point you to the right team. Every shift helps!
                                </div>
                            </details>
                        </td>
                    </tr>
                </table>
            `
        });

        // === HEADER/FOOTER BLOCKS ===
        bm.add('header', {
            label: 'Header',
            category: 'Sections',
            media: icons.header,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#2271b1">
                    <tr>
                        <td align="center" style="padding: 30px 20px;">
                            <img src="https://via.placeholder.com/200x60/2271b1/ffffff?text=YOUR+LOGO" alt="Logo" width="200" style="display: block;">
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('footer', {
            label: 'Footer',
            category: 'Sections',
            media: icons.footer,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8f9fa">
                    <tr>
                        <td align="center" style="padding: 30px 20px; font-family: Arial, sans-serif; font-size: 12px; color: #666666; line-height: 1.6;">
                            <p style="margin: 0 0 10px;">© ${new Date().getFullYear()} Your Organization. All rights reserved.</p>
                            <p style="margin: 0 0 10px;">123 Main Street, City, State 12345</p>
                            <p style="margin: 0;">
                                <a href="{{unsubscribe_url}}" style="color: #2271b1; text-decoration: underline;">Unsubscribe</a> &nbsp;|&nbsp; 
                                <a href="{{view_in_browser_url}}" style="color: #2271b1; text-decoration: underline;">View in Browser</a>
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('social-icons', {
            label: 'Social Icons',
            category: 'Sections',
            media: icons.social,
            content: `
                <table cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 20px auto;">
                    <tr>
                        <td style="padding: 0 8px;">
                            <a href="#" target="_blank">
                                <img src="https://cdn-icons-png.flaticon.com/32/733/733547.png" alt="Facebook" width="32" height="32" style="display: block;">
                            </a>
                        </td>
                        <td style="padding: 0 8px;">
                            <a href="#" target="_blank">
                                <img src="https://cdn-icons-png.flaticon.com/32/733/733579.png" alt="Twitter" width="32" height="32" style="display: block;">
                            </a>
                        </td>
                        <td style="padding: 0 8px;">
                            <a href="#" target="_blank">
                                <img src="https://cdn-icons-png.flaticon.com/32/733/733558.png" alt="Instagram" width="32" height="32" style="display: block;">
                            </a>
                        </td>
                        <td style="padding: 0 8px;">
                            <a href="#" target="_blank">
                                <img src="https://cdn-icons-png.flaticon.com/32/733/733561.png" alt="LinkedIn" width="32" height="32" style="display: block;">
                            </a>
                        </td>
                    </tr>
                </table>
            `
        });

        // === PERSONALIZATION BLOCKS ===
        bm.add('first-name', {
            label: 'First Name',
            category: 'Personalization',
            media: icons.user,
            content: '<span data-gjs-type="text">{{first_name}}</span>'
        });

        bm.add('last-name', {
            label: 'Last Name',
            category: 'Personalization',
            media: icons.user,
            content: '<span data-gjs-type="text">{{last_name}}</span>'
        });

        bm.add('email-tag', {
            label: 'Email',
            category: 'Personalization',
            media: icons.email,
            content: '<span data-gjs-type="text">{{email}}</span>'
        });

        bm.add('unsubscribe-link', {
            label: 'Unsubscribe',
            category: 'Personalization',
            media: icons.link,
            content: '<a href="{{unsubscribe_url}}" style="color: #666666;">Unsubscribe</a>'
        });

        bm.add('view-browser-link', {
            label: 'View Online',
            category: 'Personalization',
            media: icons.browser,
            content: '<a href="{{view_in_browser_url}}" style="color: #666666;">View in Browser</a>'
        });

        // === ADVANCED BLOCKS ===
        bm.add('html-block', {
            label: 'HTML',
            category: 'Advanced',
            media: icons.html,
            content: {
                type: 'html-block',
                content: `
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" data-type="html-block">
                        <tr>
                            <td style="padding: 10px;">
                                <div style="padding: 20px; background: #f5f5f5; border: 1px dashed #ccc; text-align: center; color: #666;">
                                    <span class="dashicons dashicons-editor-code" style="font-size: 24px; color: #999;"></span>
                                    <p style="margin: 10px 0 0; font-size: 13px;">Custom HTML Block</p>
                                    <p style="margin: 5px 0 0; font-size: 11px; color: #999;">Edit in Settings panel →</p>
                                </div>
                            </td>
                        </tr>
                    </table>
                `
            }
        });

        bm.add('video-block', {
            label: 'Video',
            category: 'Advanced',
            media: icons.video,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td align="center" style="padding: 10px;">
                            <a href="#video-url" target="_blank" style="display: block; position: relative;">
                                <img src="https://via.placeholder.com/600x338/1a1a1a/ffffff?text=▶+Click+to+Watch+Video" alt="Video thumbnail" width="600" style="display: block; max-width: 100%; height: auto; border-radius: 4px;">
                            </a>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">Click image to watch video</p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('gallery-block', {
            label: 'Gallery',
            category: 'Advanced',
            media: icons.gallery,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td width="50%" style="padding: 5px;">
                            <img src="https://via.placeholder.com/300x200/e0e0e0/666666?text=Image+1" alt="Gallery image 1" width="100%" style="display: block;">
                        </td>
                        <td width="50%" style="padding: 5px;">
                            <img src="https://via.placeholder.com/300x200/e0e0e0/666666?text=Image+2" alt="Gallery image 2" width="100%" style="display: block;">
                        </td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding: 5px;">
                            <img src="https://via.placeholder.com/300x200/e0e0e0/666666?text=Image+3" alt="Gallery image 3" width="100%" style="display: block;">
                        </td>
                        <td width="50%" style="padding: 5px;">
                            <img src="https://via.placeholder.com/300x200/e0e0e0/666666?text=Image+4" alt="Gallery image 4" width="100%" style="display: block;">
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('quote-block', {
            label: 'Quote',
            category: 'Advanced',
            media: icons.quote,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; border-left: 4px solid #2271b1; background: #f8f9fa;">
                            <p style="margin: 0 0 10px; font-family: Georgia, serif; font-size: 18px; font-style: italic; color: #333; line-height: 1.6;">
                                "This is an inspirational quote or testimonial that you can customize."
                            </p>
                            <p style="margin: 0; font-family: Arial, sans-serif; font-size: 14px; color: #666;">
                                — Author Name
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('list-block', {
            label: 'List',
            category: 'Advanced',
            media: icons.list,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 10px 20px; font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.8;">
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>First list item</li>
                                <li>Second list item</li>
                                <li>Third list item</li>
                            </ul>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('table-block', {
            label: 'Table',
            category: 'Advanced',
            media: icons.table,
            content: `
                <table width="100%" cellpadding="10" cellspacing="0" border="0" style="border-collapse: collapse; font-family: Arial, sans-serif; font-size: 14px;">
                    <tr style="background: #2271b1; color: #fff;">
                        <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Header 1</th>
                        <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Header 2</th>
                        <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Header 3</th>
                    </tr>
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 1, Cell 1</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 1, Cell 2</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 1, Cell 3</td>
                    </tr>
                    <tr style="background: #f8f9fa;">
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 2, Cell 1</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 2, Cell 2</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">Row 2, Cell 3</td>
                    </tr>
                </table>
            `
        });

        bm.add('countdown-block', {
            label: 'Countdown',
            category: 'Advanced',
            media: icons.countdown,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td align="center" style="padding: 20px;">
                            <p style="margin: 0 0 15px; font-family: Arial, sans-serif; font-size: 16px; color: #333;">Event starts in:</p>
                            <table cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding: 0 10px;">
                                        <div style="background: #2271b1; color: #fff; padding: 15px 20px; border-radius: 4px; font-family: Arial, sans-serif; font-size: 24px; font-weight: bold;">00</div>
                                        <p style="margin: 5px 0 0; font-size: 12px; color: #666;">DAYS</p>
                                    </td>
                                    <td align="center" style="padding: 0 10px;">
                                        <div style="background: #2271b1; color: #fff; padding: 15px 20px; border-radius: 4px; font-family: Arial, sans-serif; font-size: 24px; font-weight: bold;">00</div>
                                        <p style="margin: 5px 0 0; font-size: 12px; color: #666;">HOURS</p>
                                    </td>
                                    <td align="center" style="padding: 0 10px;">
                                        <div style="background: #2271b1; color: #fff; padding: 15px 20px; border-radius: 4px; font-family: Arial, sans-serif; font-size: 24px; font-weight: bold;">00</div>
                                        <p style="margin: 5px 0 0; font-size: 12px; color: #666;">MINS</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            `
        });

        // === WORDPRESS BLOCKS ===
        bm.add('posts-block', {
            label: 'Latest Posts',
            category: 'WordPress',
            media: icons.posts,
            content: {
                type: 'latest-posts',
                content: `
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" data-type="latest-posts">
                        <tr>
                            <td style="padding: 20px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">
                                <p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">
                                    [newsletter_posts count="3" show_image="true"]
                                </p>
                                <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                    Displays latest WordPress posts. Configure in Settings panel →
                                </p>
                            </td>
                        </tr>
                    </table>
                `
            }
        });

        bm.add('now-next', {
            label: 'Now and Next',
            category: 'WordPress',
            media: icons.calendar,
            content: `
                <table class="nl-now-next" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 16px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">
                                [nl-now-next]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                This Week and Next Week events — compact 2-column list
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('shortcode-block', {
            label: 'Shortcode',
            category: 'WordPress',
            media: icons.shortcode,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">
                                [your_shortcode]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Shortcode will be rendered when email is sent
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('pta-directory', {
            label: 'PTA Directory',
            category: 'WordPress',
            media: icons.pta,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">
                                [pta-roles-directory columns="2"]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Full PTA directory - all departments and roles
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('pta-open-positions', {
            label: 'Open Positions',
            category: 'WordPress',
            media: icons.pta,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #fff8e5; border: 2px dashed #f0c14b; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #b7791f;">
                                [pta-open-positions limit="5"]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Shows unfilled volunteer positions - We need YOU!
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('pta-department', {
            label: 'Department',
            category: 'WordPress',
            media: icons.pta,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #f0f6fc; border: 2px dashed #2271b1; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #2271b1;">
                                [pta-department-roles department="executive"]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Shows roles in a specific department
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('pta-org-chart', {
            label: 'Org Chart',
            category: 'WordPress',
            media: icons.pta,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #f5f0ff; border: 2px dashed #7c3aed; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #7c3aed;">
                                [pta-org-chart interactive="false"]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Visual organization hierarchy
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('pta-vp', {
            label: 'Department VP',
            category: 'WordPress',
            media: icons.user,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background: #f0fdf4; border: 2px dashed #22c55e; text-align: center;">
                            <p style="margin: 0; font-family: monospace; font-size: 14px; color: #16a34a;">
                                [pta-department-vp department="fundraising"]
                            </p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                                Shows the VP for a department
                            </p>
                        </td>
                    </tr>
                </table>
            `
        });
    }

    /**
     * Customize the Rich Text Editor toolbar that appears when the user
     * clicks editable text. We replace the default link action
     * with one that:
     *   - Prompts for a URL (preserving the current href if editing)
     *   - Auto-prepends https:// when the user types a bare domain
     *   - Sets target="_blank" + rel="noopener" so newsletter links
     *     open externally
     *   - Lets the user clear the link by leaving the URL empty
     * The link button is also given a distinctive label so it's easier
     * to find on the floating toolbar.
     */
    function customizeRichTextEditor() {
        if (!editor || !editor.RichTextEditor) return;
        var rte = editor.RichTextEditor;

        // Remove the default link action (if present) before re-adding.
        try { rte.remove('link'); } catch (e) { /* not critical */ }

        rte.add('link', {
            icon: '<span style="font-weight:600;letter-spacing:0.3px;">' +
                  '<svg style="vertical-align:middle;margin-right:3px;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' +
                  'Link</span>',
            attributes: { title: 'Insert / edit link', 'data-rte-link': '1' },
            // Show the action as "active" while the cursor is inside an <a>
            state: function (rte, doc) {
                if (rte && rte.selection) {
                    var sel = rte.selection();
                    var node = sel && sel.anchorNode;
                    while (node && node !== doc.body) {
                        if (node.nodeName === 'A') return 1;
                        node = node.parentNode;
                    }
                }
                return 0;
            },
            result: function (rte) {
                var doc = rte.doc || document;
                // Find an existing <a> in the current selection, if any
                var existing = null;
                var sel = rte.selection ? rte.selection() : null;
                if (sel && sel.anchorNode) {
                    var node = sel.anchorNode;
                    while (node && node !== doc.body) {
                        if (node.nodeName === 'A') { existing = node; break; }
                        node = node.parentNode;
                    }
                }
                var currentUrl = existing ? existing.getAttribute('href') || '' : '';
                var url = window.prompt(
                    'Enter URL (leave blank to remove the link):',
                    currentUrl
                );
                if (url === null) return; // user cancelled

                url = url.trim();
                if (url === '') {
                    // Strip the link if one exists
                    if (existing) {
                        var parent = existing.parentNode;
                        while (existing.firstChild) {
                            parent.insertBefore(existing.firstChild, existing);
                        }
                        parent.removeChild(existing);
                    }
                    return;
                }

                // Auto-prepend https:// for bare domains, but allow
                // mailto:, tel:, #anchor, and merge tokens unmodified.
                if (!/^([a-z][a-z0-9+.-]*:|#|\{\{)/i.test(url)) {
                    url = 'https://' + url;
                }

                if (existing) {
                    existing.setAttribute('href', url);
                    existing.setAttribute('target', '_blank');
                    existing.setAttribute('rel', 'noopener');
                } else {
                    rte.exec('createLink', url);
                    // Apply target/rel to all new <a> in the selection
                    setTimeout(function () {
                        if (sel && sel.anchorNode) {
                            var n = sel.anchorNode;
                            while (n && n !== doc.body) {
                                if (n.nodeName === 'A') {
                                    n.setAttribute('target', '_blank');
                                    n.setAttribute('rel', 'noopener');
                                    break;
                                }
                                n = n.parentNode;
                            }
                        }
                    }, 0);
                }
            }
        });
    }

    var NL_COL_MIN = 15;

    /**
     * Equal-width 1/2/3 column row. Columns are the layout frame;
     * content (not other column rows) goes inside each cell.
     */
    function sectionGroupHtml() {
        return '<table class="nl-section nl-section-empty" width="100%" height="260" cellpadding="0" cellspacing="0" border="0" style="height: 260px;">'
            + '<tr><td class="nl-section-body" height="260" valign="middle" style="height: 260px; padding: 56px 24px;">'
            + '<div class="nl-section-hint"></div>'
            + '</td></tr></table>';
    }

    function columnRowHtml(count) {
        var n = count === 3 ? 3 : (count === 2 ? 2 : 1);
        var widths = n === 3 ? [33, 34, 33] : n === 2 ? [50, 50] : [100];
        var labels = n === 1 ? ['Add content here'] : n === 2 ? ['Left column', 'Right column'] : ['Column 1', 'Column 2', 'Column 3'];
        var cells = '';
        for (var i = 0; i < n; i++) {
            cells += '<td class="nl-stack-col nl-column" width="' + widths[i] + '%" valign="top" style="padding: 10px; width: ' + widths[i] + '%;">'
                + '<p>' + labels[i] + '</p></td>';
        }
        return '<table class="nl-stack-cols" width="100%" cellpadding="0" cellspacing="0" border="0">'
            + '<tr>' + cells + '</tr></table>';
    }

    /**
     * Move leftover percent across the other columns so the row stays 100%.
     * Keep in sync with tests/test-newsletter-designer-layout.php.
     */
    function redistributeColumnWidths(widths, index, newVal) {
        var n = widths.length;
        if (n < 1) {
            return [];
        }
        if (n === 1) {
            return [100];
        }
        var min = NL_COL_MIN;
        var max = 100 - min * (n - 1);
        newVal = Number(newVal);
        if (!isFinite(newVal)) {
            newVal = widths[index];
        }
        newVal = Math.max(min, Math.min(max, newVal));
        var next = widths.slice();
        var old = next[index];
        var delta = newVal - old;
        next[index] = newVal;
        var others = [];
        var otherSum = 0;
        var i;
        for (i = 0; i < n; i++) {
            if (i !== index) {
                others.push(i);
                otherSum += next[i];
            }
        }
        if (otherSum <= 0) {
            var even = (100 - newVal) / others.length;
            for (i = 0; i < others.length; i++) {
                next[others[i]] = even;
            }
        } else {
            for (i = 0; i < others.length; i++) {
                var oi = others[i];
                next[oi] = next[oi] - delta * (next[oi] / otherSum);
            }
        }
        for (i = 0; i < n; i++) {
            if (i !== index && next[i] < min) {
                next[i] = min;
            }
        }
        var sum = 0;
        for (i = 0; i < n; i++) {
            sum += next[i];
        }
        if (Math.abs(sum - 100) > 0.01) {
            var fix = 100 - sum;
            var grow = index === 0 ? 1 : 0;
            next[grow] = Math.max(min, Math.min(max, next[grow] + fix));
        }
        var rounded = [];
        var roundedSum = 0;
        for (i = 0; i < n; i++) {
            rounded[i] = Math.round(next[i]);
            roundedSum += rounded[i];
        }
        rounded[n - 1] += (100 - roundedSum);
        if (rounded[n - 1] < min) {
            var deficit = min - rounded[n - 1];
            rounded[n - 1] = min;
            for (i = 0; i < n - 1 && deficit > 0; i++) {
                var take = Math.min(deficit, rounded[i] - min);
                rounded[i] -= take;
                deficit -= take;
            }
        }
        return rounded;
    }

    function findColumnCells(rowComp) {
        var found = [];
        function walk(c) {
            if (!c || !c.get) {
                return;
            }
            if (c.get('type') === 'nl-column') {
                found.push(c);
                return;
            }
            var tag = String(c.get('tagName') || '').toLowerCase();
            var cls = String((c.getAttributes() || {}).class || '');
            if (tag === 'td' && cls.indexOf('nl-column') !== -1) {
                found.push(c);
                return;
            }
            var kids = c.components && c.components();
            if (kids && kids.forEach) {
                kids.forEach(walk);
            }
        }
        walk(rowComp);
        return found;
    }

    function rotateListLeft(items) {
        if (!items || items.length < 2) {
            return items ? items.slice() : [];
        }
        return items.slice(1).concat([items[0]]);
    }

    function getSwappableColumnRow(component) {
        if (!component || !component.get) {
            return null;
        }
        var row = component.get('type') === 'nl-columns' ? component : findAncestorColumns(component);
        if (!row) {
            return null;
        }
        return findColumnCells(row).length >= 2 ? row : null;
    }

    function cycleColumnContents(row) {
        var cells = findColumnCells(row);
        if (cells.length < 2) {
            return false;
        }
        var parent = cells[0].parent && cells[0].parent();
        if (!parent) {
            return false;
        }
        var i;
        for (i = 1; i < cells.length; i++) {
            if ((cells[i].parent && cells[i].parent()) !== parent) {
                return false;
            }
        }
        var widths = getColumnWidths(row);
        var rotated = rotateListLeft(cells);
        var wasSyncing = syncingRowGaps;
        syncingRowGaps = true;
        try {
            rotated.forEach(function(cell, idx) {
                hoistTo(cell, parent, idx);
            });
        } finally {
            syncingRowGaps = wasSyncing;
        }
        applyColumnWidths(row, widths);
        constrainColumnImage(row);
        return true;
    }

    function cycleSelectedColumns() {
        var row = getSwappableColumnRow(editor && editor.getSelected && editor.getSelected());
        if (!row) {
            return false;
        }
        var ok = cycleColumnContents(row);
        if (ok && editor && editor.select) {
            editor.select(row);
        }
        updateMoveButtons(getMovableRow(row));
        return ok;
    }

    function parseWidthPct(comp) {
        var attrs = comp.getAttributes ? comp.getAttributes() : {};
        var w = attrs.width || '';
        var n = parseFloat(String(w).replace('%', ''));
        if (isFinite(n) && n > 0) {
            return n;
        }
        var style = comp.getStyle ? comp.getStyle() : {};
        n = parseFloat(String(style.width || '').replace('%', ''));
        return isFinite(n) && n > 0 ? n : 0;
    }

    function getColumnWidths(rowComp) {
        var cols = findColumnCells(rowComp);
        var widths = cols.map(parseWidthPct);
        var n = widths.length;
        if (!n) {
            return [];
        }
        var missing = widths.some(function(w) { return !w; });
        if (missing) {
            var even = Math.floor(100 / n);
            widths = [];
            for (var i = 0; i < n; i++) {
                widths.push(i === n - 1 ? 100 - even * (n - 1) : even);
            }
        }
        return widths;
    }

    function applyColumnWidths(rowComp, widths) {
        var cols = findColumnCells(rowComp);
        widths.forEach(function(pct, i) {
            if (!cols[i]) {
                return;
            }
            var rounded = Math.round(pct);
            cols[i].addAttributes({ width: rounded + '%' });
            var style = cols[i].getStyle() || {};
            style.width = rounded + '%';
            if (!style.padding && !style['padding-left'] && !style['padding-right']) {
                style.padding = '10px';
            }
            cols[i].setStyle(style);
        });
    }

    function componentIsInColumn(component) {
        var parent = component && component.parent ? component.parent() : null;
        while (parent) {
            if (parent.get && parent.get('type') === 'nl-column') {
                return true;
            }
            var cls = String((parent.getAttributes && parent.getAttributes() || {}).class || '');
            if (cls.indexOf('nl-column') !== -1 || cls.indexOf('nl-stack-col') !== -1) {
                return true;
            }
            parent = parent.parent ? parent.parent() : null;
        }
        return false;
    }

    function constrainColumnImage(component) {
        if (!component || !component.get) {
            return;
        }
        var type = component.get('type');
        if (type !== 'image' && type !== 'email-image') {
            var kids = component.components && component.components();
            if (kids && kids.forEach) {
                kids.forEach(constrainColumnImage);
            }
            return;
        }
        if (!componentIsInColumn(component)) {
            return;
        }
        var attrs = component.getAttributes ? component.getAttributes() : {};
        var rawWidth = attrs.width || component.get('width') || '';
        var px = parseInt(String(rawWidth).replace('px', ''), 10);
        if (rawWidth === '100%' || (isFinite(px) && px > 0 && px < 280)) {
            component.addStyle({
                'max-width': '100%',
                height: 'auto',
                display: 'block'
            });
            return;
        }
        component.addAttributes({ width: '100%' });
        component.addStyle({
            width: '100%',
            'max-width': '100%',
            height: 'auto',
            display: 'block'
        });
    }

    function isColumnFrame(component) {
        if (!component || !component.get) {
            return false;
        }
        var type = component.get('type');
        if (type === 'nl-columns' || type === 'nl-column' || type === 'nl-section' || type === 'nl-section-body') {
            return true;
        }
        var cls = String((component.getAttributes() || {}).class || '');
        if (cls.indexOf('nl-stack-cols') !== -1 || cls.indexOf('nl-column') !== -1 || cls.indexOf('nl-section') !== -1) {
            return true;
        }
        var tag = String(component.get('tagName') || '').toLowerCase();
        if (['table', 'tbody', 'thead', 'tr'].indexOf(tag) !== -1 && findAncestorColumns(component)) {
            return true;
        }
        return false;
    }

    function findAncestorSection(component) {
        var p = component && component.parent ? component.parent() : null;
        while (p) {
            if (p.get && p.get('type') === 'nl-section') {
                return p;
            }
            var cls = String((p.getAttributes && p.getAttributes() || {}).class || '');
            if (cls.indexOf('nl-section') !== -1 && String(p.get('tagName') || '').toLowerCase() === 'table'
                && cls.indexOf('nl-section-body') === -1) {
                return p;
            }
            p = p.parent ? p.parent() : null;
        }
        return null;
    }

    function findAncestorColumns(component) {
        var p = component && component.parent ? component.parent() : null;
        while (p) {
            if (p.get && p.get('type') === 'nl-columns') {
                return p;
            }
            var cls = String((p.getAttributes && p.getAttributes() || {}).class || '');
            if (cls.indexOf('nl-stack-cols') !== -1 && String(p.get('tagName') || '').toLowerCase() === 'table') {
                return p;
            }
            p = p.parent ? p.parent() : null;
        }
        return null;
    }

    function clearSectionHint(component) {
        var parent = component && component.parent ? component.parent() : null;
        if (!parent || (component.getAttributes && String((component.getAttributes() || {}).class || '').indexOf('nl-section-hint') !== -1)) {
            return;
        }
        var type = parent.get ? parent.get('type') : '';
        var cls = String((parent.getAttributes && parent.getAttributes() || {}).class || '');
        if (type !== 'nl-section-body' && cls.indexOf('nl-section-body') === -1) {
            return;
        }
        var kids = [];
        if (parent.components) {
            var comps = parent.components();
            if (comps && comps.forEach) {
                comps.forEach(function(c) { kids.push(c); });
            } else if (comps && comps.models) {
                kids = comps.models.slice();
            }
        }
        kids.forEach(function(k) {
            if (k === component) {
                return;
            }
            var kcls = String((k.getAttributes && k.getAttributes() || {}).class || '');
            if (kcls.indexOf('nl-section-hint') !== -1) {
                try { k.remove(); } catch (e) { /* already gone */ }
            }
        });
        applySectionFrame(parent, false);
    }

    function hoistNestedSection(component) {
        if (!component || component.get('type') !== 'nl-section') {
            return;
        }
        var outer = findAncestorSection(component);
        var inCol = findAncestorColumns(component);
        if (!outer && !inCol) {
            return;
        }
        var dest;
        var at;
        if (outer) {
            dest = outer.parent && outer.parent();
            at = typeof outer.index === 'function' ? outer.index() + 1 : undefined;
        } else {
            dest = findEmailCanvasCell() || (editor && editor.getWrapper && editor.getWrapper());
            var anchor = inCol;
            while (anchor && anchor.parent && anchor.parent() !== dest) {
                var next = anchor.parent();
                if (!next || next === dest) {
                    break;
                }
                anchor = next;
            }
            at = anchor && typeof anchor.index === 'function' ? anchor.index() + 1 : undefined;
        }
        if (!dest) {
            return;
        }
        try {
            if (typeof component.move === 'function') {
                component.move(dest, { at: at });
            } else {
                var json = component.toJSON();
                component.remove();
                dest.components().add(json, { at: at });
            }
        } catch (e) { /* drop already rejected */ }
    }

    function hoistNowNext(component) {
        if (!component || !component.get) {
            return;
        }
        var cls = String((component.getAttributes && component.getAttributes() || {}).class || '');
        if (cls.indexOf('nl-now-next') === -1 && component.get('type') !== 'nl-now-next') {
            return;
        }
        var inCol = findAncestorColumns(component);
        if (!inCol) {
            return;
        }
        var section = findAncestorSection(component);
        var dest = null;
        if (section) {
            dest = findSectionBodyChild(section) || (section.parent && section.parent());
        } else {
            dest = findEmailCanvasCell() || (editor && editor.getWrapper && editor.getWrapper());
        }
        if (!dest || dest === component) {
            return;
        }
        var at;
        if (section) {
            at = typeof inCol.index === 'function' ? inCol.index() + 1 : undefined;
        } else {
            var anchor = inCol;
            while (anchor && anchor.parent && anchor.parent() !== dest) {
                var next = anchor.parent();
                if (!next || next === dest) {
                    break;
                }
                anchor = next;
            }
            at = anchor && typeof anchor.index === 'function' ? anchor.index() + 1 : undefined;
        }
        hoistTo(component, dest, at);
    }

    function hoistNestedColumns(component) {
        if (!component || component.get('type') !== 'nl-columns') {
            return;
        }
        var outer = findAncestorColumns(component);
        if (!outer) {
            return;
        }
        var dest = outer.parent && outer.parent();
        if (!dest) {
            return;
        }
        var at = typeof outer.index === 'function' ? outer.index() + 1 : undefined;
        try {
            if (typeof component.move === 'function') {
                component.move(dest, { at: at });
            } else {
                var json = component.toJSON();
                component.remove();
                dest.components().add(json, { at: at });
            }
        } catch (e) { /* drop already rejected */ }
    }

    function renderColumnWidthSliders(container, component) {
        if (!container || !component) {
            return;
        }
        var widths = getColumnWidths(component);
        container.innerHTML = '';
        if (widths.length < 2) {
            container.innerHTML = '<p class="description" style="margin:0;">This row uses the full width.</p>';
            return;
        }
        var max = 100 - NL_COL_MIN * (widths.length - 1);
        widths.forEach(function(w, i) {
            var row = document.createElement('label');
            row.className = 'pta-nl-col-width-row';
            row.innerHTML = '<span>Column ' + (i + 1) + '</span>'
                + '<input type="range" min="' + NL_COL_MIN + '" max="' + max + '" step="1" value="' + Math.round(w) + '" data-col="' + i + '">'
                + '<span class="pta-nl-col-width-val">' + Math.round(w) + '%</span>';
            container.appendChild(row);
        });
        var inputs = container.querySelectorAll('input[type=range]');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].addEventListener('input', function() {
                var idx = parseInt(this.getAttribute('data-col'), 10);
                var next = redistributeColumnWidths(getColumnWidths(component), idx, parseFloat(this.value));
                applyColumnWidths(component, next);
                renderColumnWidthSliders(container, component);
            });
        }
    }

    var syncingRowGaps = false;

    function isRowGap(component) {
        return !!(component && component.get && component.get('type') === 'nl-row-gap');
    }

    function wrapperChildList(wrapper) {
        var list = [];
        if (!wrapper || !wrapper.components) {
            return list;
        }
        var comps = wrapper.components();
        if (comps && comps.forEach) {
            comps.forEach(function(c) { list.push(c); });
        } else if (comps && comps.each) {
            comps.each(function(c) { list.push(c); });
        } else if (comps && comps.models) {
            list = comps.models.slice();
        }
        return list;
    }

    function isSectionHint(component) {
        var cls = String((component && component.getAttributes && component.getAttributes() || {}).class || '');
        return cls.indexOf('nl-section-hint') !== -1;
    }

    function isSectionBody(component) {
        if (!component || !component.get) {
            return false;
        }
        if (component.get('type') === 'nl-section-body') {
            return true;
        }
        var tag = String(component.get('tagName') || '').toLowerCase();
        var cls = String((component.getAttributes && component.getAttributes() || {}).class || '');
        return tag === 'td' && cls.indexOf('nl-section-body') !== -1;
    }

    function findAllSectionBodies() {
        var out = [];
        function walk(c) {
            if (!c) {
                return;
            }
            if (isSectionBody(c)) {
                out.push(c);
                return;
            }
            wrapperChildList(c).forEach(walk);
        }
        walk(editor && editor.getWrapper && editor.getWrapper());
        return out;
    }

    function findSectionBodyChild(section) {
        var found = null;
        function walk(c) {
            if (!c || found) {
                return;
            }
            if (isSectionBody(c)) {
                found = c;
                return;
            }
            wrapperChildList(c).forEach(walk);
        }
        walk(section);
        return found;
    }

    function parentIsSectionChrome(parent) {
        if (!parent || !parent.get) {
            return false;
        }
        if (parent.get('type') === 'nl-section') {
            return true;
        }
        var tag = String(parent.get('tagName') || '').toLowerCase();
        if (tag !== 'tr' && tag !== 'tbody' && tag !== 'thead') {
            return false;
        }
        var p = parent.parent && parent.parent();
        return !!(p && p.get && p.get('type') === 'nl-section');
    }

    function isSectionInternal(component) {
        if (!component || !component.get) {
            return false;
        }
        var type = component.get('type');
        if (type === 'nl-section' || type === 'nl-section-body' || type === 'nl-section-hint') {
            return true;
        }
        var tag = String(component.get('tagName') || '').toLowerCase();
        if (tag === 'tr' || tag === 'tbody' || tag === 'thead' || tag === 'tfoot') {
            return true;
        }
        var cls = String((component.getAttributes && component.getAttributes() || {}).class || '');
        return cls.indexOf('nl-section-hint') !== -1 || cls.indexOf('nl-section-body') !== -1;
    }

    function setComponentAttr(comp, name, value) {
        if (!comp || !comp.getAttributes || !comp.setAttributes) {
            return;
        }
        var attrs = {};
        var cur = comp.getAttributes() || {};
        Object.keys(cur).forEach(function(k) {
            attrs[k] = cur[k];
        });
        if (value === null || value === '') {
            delete attrs[name];
        } else {
            attrs[name] = value;
        }
        comp.setAttributes(attrs);
    }

    function toggleClass(comp, className, on) {
        if (!comp || !comp.getAttributes) {
            return;
        }
        var attrs = comp.getAttributes() || {};
        var parts = String(attrs.class || '').split(/\s+/).filter(Boolean);
        var has = parts.indexOf(className) !== -1;
        if (on && !has) {
            parts.push(className);
        }
        if (!on && has) {
            parts = parts.filter(function(c) { return c !== className; });
        }
        setComponentAttr(comp, 'class', parts.join(' '));
    }

    function applySectionFrame(body, empty) {
        var section = body && findAncestorSection(body);
        if (!section && body && body.get && body.get('type') === 'nl-section') {
            section = body;
            body = findSectionBodyChild(section);
        }
        if (section) {
            toggleClass(section, 'nl-section-empty', !!empty);
            var ss = section.getStyle ? (section.getStyle() || {}) : {};
            if (empty) {
                ss.height = '260px';
                setComponentAttr(section, 'height', '260');
            } else {
                delete ss.height;
                setComponentAttr(section, 'height', null);
            }
            if (section.setStyle) {
                section.setStyle(ss);
            }
        }
        if (!body || !body.setStyle) {
            return;
        }
        var st = body.getStyle() || {};
        if (empty) {
            st.height = '260px';
            st.padding = '56px 24px';
            st['vertical-align'] = 'middle';
            setComponentAttr(body, 'height', '260');
            setComponentAttr(body, 'valign', 'middle');
        } else {
            delete st.height;
            st.padding = '0';
            st['vertical-align'] = 'top';
            setComponentAttr(body, 'height', null);
            setComponentAttr(body, 'valign', 'top');
        }
        body.setStyle(st);
    }

    function hoistTo(component, dest, at) {
        if (!component || !dest || component === dest) {
            return;
        }
        try {
            if (typeof component.move === 'function') {
                component.move(dest, { at: at });
            } else {
                var json = component.toJSON();
                component.remove();
                dest.components().add(json, { at: at });
            }
        } catch (e) { /* sorter already placed it */ }
    }

    function hoistToWrapper(component, at) {
        hoistTo(component, editor && editor.getWrapper && editor.getWrapper(), at);
    }

    function emptyGapsIn(container) {
        if (!container) {
            return;
        }
        wrapperChildList(container).forEach(function(gap) {
            if (!isRowGap(gap)) {
                return;
            }
            var kids = [];
            var comps = gap.components && gap.components();
            if (comps && comps.forEach) {
                comps.forEach(function(c) { kids.push(c); });
            } else if (comps && comps.each) {
                comps.each(function(c) { kids.push(c); });
            }
            var at = typeof gap.index === 'function' ? gap.index() : 0;
            kids.forEach(function(child, i) {
                hoistTo(child, container, at + i);
            });
        });
    }

    function emptyGapsIntoWrapper() {
        var canvas = findEmailCanvasCell();
        if (canvas) {
            emptyGapsIn(canvas);
        }
        emptyGapsIn(editor && editor.getWrapper && editor.getWrapper());
        findAllSectionBodies().forEach(emptyGapsIn);
    }

    function syncGapsInContainer(container, skipHints) {
        if (!container || !container.components) {
            return;
        }
        emptyGapsIn(container);
        var kids = wrapperChildList(container);
        var reals = kids.filter(function(c) {
            return !isRowGap(c) && !(skipHints && isSectionHint(c));
        });
        if (skipHints && reals.length === 0) {
            kids.forEach(function(c) {
                if (isRowGap(c)) {
                    c.remove();
                }
            });
            return;
        }
        var expected = reals.length * 2 + 1;
        var ok = kids.length === expected;
        if (ok) {
            for (var i = 0; i < kids.length; i++) {
                if ((i % 2 === 0) !== isRowGap(kids[i])) {
                    ok = false;
                    break;
                }
                if (i % 2 === 1 && kids[i] !== reals[(i - 1) / 2]) {
                    ok = false;
                    break;
                }
            }
        }
        if (ok) {
            return;
        }
        kids.forEach(function(c) {
            if (isRowGap(c)) {
                c.remove();
            }
        });
        reals = wrapperChildList(container).filter(function(c) {
            return !isRowGap(c) && !(skipHints && isSectionHint(c));
        });
        container.components().add({ type: 'nl-row-gap' }, { at: 0 });
        reals.forEach(function(c) {
            var at = typeof c.index === 'function' ? c.index() + 1 : undefined;
            container.components().add({ type: 'nl-row-gap' }, { at: at });
        });
    }

    function componentTag(component) {
        return String(component && component.get ? component.get('tagName') || '' : '').toLowerCase();
    }

    function containsDescendant(root, target) {
        if (!root || !target) {
            return false;
        }
        if (root === target) {
            return true;
        }
        var kids = wrapperChildList(root);
        for (var i = 0; i < kids.length; i++) {
            if (containsDescendant(kids[i], target)) {
                return true;
            }
        }
        return false;
    }

    function isEmailCanvasTable(component) {
        if (!component || componentTag(component) !== 'table') {
            return false;
        }
        var attrs = (component.getAttributes && component.getAttributes()) || {};
        var width = String(attrs.width || '');
        if (width === '600' || width === '600px') {
            return true;
        }
        var st = (component.getStyle && component.getStyle()) || {};
        var mw = String(st['max-width'] || st.maxWidth || '');
        var tw = String(st.width || '');
        return mw.indexOf('600') !== -1 || tw === '600px' || tw === '600';
    }

    function findEmailCanvasTable() {
        var found = null;
        function walk(c) {
            if (!c || found) {
                return;
            }
            if (isEmailCanvasTable(c)) {
                found = c;
                return;
            }
            wrapperChildList(c).forEach(walk);
        }
        walk(editor && editor.getWrapper && editor.getWrapper());
        return found;
    }

    function getDirectTableCells(table) {
        var cells = [];
        function addCellsFromRow(row) {
            wrapperChildList(row).forEach(function(cell) {
                var tag = componentTag(cell);
                if (tag === 'td' || tag === 'th') {
                    cells.push(cell);
                }
            });
        }
        wrapperChildList(table).forEach(function(child) {
            var tag = componentTag(child);
            if (tag === 'tr') {
                addCellsFromRow(child);
                return;
            }
            if (tag === 'tbody' || tag === 'thead' || tag === 'tfoot') {
                wrapperChildList(child).forEach(function(row) {
                    if (componentTag(row) === 'tr') {
                        addCellsFromRow(row);
                    }
                });
            }
        });
        return cells;
    }

    function looksLikeFooterCell(cell) {
        var html = '';
        try {
            html = cell && cell.toHTML ? String(cell.toHTML()) : '';
        } catch (e) {
            html = '';
        }
        html = html.toLowerCase();
        return html.indexOf('unsubscribe') !== -1
            || html.indexOf('view_in_browser') !== -1
            || html.indexOf('view in browser') !== -1;
    }

    function findEmailCanvasCell() {
        var table = findEmailCanvasTable();
        if (!table) {
            return null;
        }
        var cells = getDirectTableCells(table);
        if (!cells.length) {
            return null;
        }
        var footerIdx = -1;
        for (var i = cells.length - 1; i >= 0; i--) {
            if (looksLikeFooterCell(cells[i])) {
                footerIdx = i;
                break;
            }
        }
        var end = footerIdx >= 0 ? footerIdx : cells.length;
        var candidates = cells.slice(0, end);
        if (!candidates.length) {
            return cells[0];
        }
        return candidates[candidates.length - 1];
    }

    function pinEmailCanvasWidth() {
        var table = findEmailCanvasTable();
        if (!table || !table.addStyle) {
            return;
        }
        table.addStyle({ width: '600px', 'max-width': '600px' });
        var attrs = (table.getAttributes && table.getAttributes()) || {};
        if (!attrs.width || String(attrs.width) === '100%') {
            setComponentAttr(table, 'width', '600');
        }
    }

    function isCanvasBlock(component) {
        if (!component || !component.get || isRowGap(component) || isSectionHint(component)) {
            return false;
        }
        var type = component.get('type');
        if (type === 'nl-section' || type === 'nl-columns') {
            return true;
        }
        var tag = componentTag(component);
        var cls = String((component.getAttributes && component.getAttributes() || {}).class || '');
        if (tag === 'table' && cls.indexOf('nl-section') !== -1 && cls.indexOf('nl-section-body') === -1) {
            return true;
        }
        if (tag === 'table' && cls.indexOf('nl-stack-cols') !== -1) {
            return true;
        }
        if (tag === 'table' && cls.indexOf('nl-now-next') !== -1) {
            return true;
        }
        if (tag === 'table' && cls.indexOf('nl-divider') !== -1) {
            return true;
        }
        return false;
    }

    function hoistEscapedBlocksIntoCanvas() {
        var canvasTable = findEmailCanvasTable();
        var cell = findEmailCanvasCell();
        if (!canvasTable || !cell) {
            return;
        }
        var parent = canvasTable.parent && canvasTable.parent();
        if (parent) {
            wrapperChildList(parent).slice().forEach(function(sib) {
                if (sib === canvasTable || sib === cell || isRowGap(sib)) {
                    return;
                }
                if (containsDescendant(sib, canvasTable)) {
                    return;
                }
                hoistTo(sib, cell);
            });
        }
        var wrapper = editor && editor.getWrapper && editor.getWrapper();
        wrapperChildList(wrapper).slice().forEach(function(child) {
            if (isRowGap(child)) {
                return;
            }
            if (child === canvasTable || containsDescendant(child, canvasTable)) {
                return;
            }
            hoistTo(child, cell);
        });
    }

    function syncCanvasBlockGaps(cell) {
        if (!cell) {
            return;
        }
        emptyGapsIn(cell);
        wrapperChildList(cell).forEach(function(c) {
            if (isRowGap(c)) {
                c.remove();
            }
        });
        var blocks = wrapperChildList(cell).filter(isCanvasBlock);
        if (!blocks.length) {
            cell.components().add({ type: 'nl-row-gap' });
            return;
        }
        blocks.forEach(function(block, i) {
            if (i === 0) {
                var startAt = typeof block.index === 'function' ? block.index() : 0;
                cell.components().add({ type: 'nl-row-gap' }, { at: startAt });
            }
            var after = typeof block.index === 'function' ? block.index() + 1 : undefined;
            cell.components().add({ type: 'nl-row-gap' }, { at: after });
        });
    }

    function clearContainerGaps(container) {
        if (!container) {
            return;
        }
        emptyGapsIn(container);
        wrapperChildList(container).forEach(function(c) {
            if (isRowGap(c)) {
                c.remove();
            }
        });
    }

    function syncRowGaps() {
        if (!editor || syncingRowGaps) {
            return;
        }
        var wrapper = editor.getWrapper && editor.getWrapper();
        if (!wrapper) {
            return;
        }
        syncingRowGaps = true;
        try {
            var canvas = findEmailCanvasCell();
            if (canvas) {
                pinEmailCanvasWidth();
                hoistEscapedBlocksIntoCanvas();
                syncCanvasBlockGaps(canvas);
                clearContainerGaps(wrapper);
            } else {
                syncGapsInContainer(wrapper, false);
            }
            findAllSectionBodies().forEach(function(body) {
                emptyGapsIn(body);
                wrapperChildList(body).forEach(function(c) {
                    if (isRowGap(c)) {
                        c.remove();
                    }
                });
            });
        } finally {
            syncingRowGaps = false;
        }
    }

    function getMovableRow(component) {
        if (!component || !component.get || isRowGap(component)) {
            return null;
        }
        if (component.get('type') === 'nl-section') {
            return component;
        }
        var section = findAncestorSection(component);
        if (section) {
            return section;
        }
        if (component.get('type') === 'nl-columns') {
            return component;
        }
        var cols = findAncestorColumns(component);
        if (cols) {
            return cols;
        }
        var wrapper = editor && editor.getWrapper && editor.getWrapper();
        if (!wrapper) {
            return null;
        }
        var p = component;
        while (p.parent && p.parent() && p.parent() !== wrapper) {
            p = p.parent();
        }
        if (p.parent && p.parent() === wrapper && !isRowGap(p)) {
            return p;
        }
        return null;
    }

    function rowMoveContainer(row) {
        if (row && row.parent && row.parent()) {
            return row.parent();
        }
        return findEmailCanvasCell() || (editor && editor.getWrapper && editor.getWrapper());
    }

    function rowMoveState(row) {
        var container = rowMoveContainer(row);
        var reals = wrapperChildList(container).filter(function(c) { return !isRowGap(c); });
        var idx = -1;
        for (var i = 0; i < reals.length; i++) {
            if (reals[i] === row) {
                idx = i;
                break;
            }
        }
        return {
            reals: reals,
            index: idx,
            canUp: idx > 0,
            canDown: idx >= 0 && idx < reals.length - 1
        };
    }

    function updateMoveButtons(row) {
        var state = row ? rowMoveState(row) : { canUp: false, canDown: false };
        $('#btn-row-up').prop('disabled', !state.canUp);
        $('#btn-row-down').prop('disabled', !state.canDown);
        var selected = editor && editor.getSelected && editor.getSelected();
        $('#btn-swap-cols').prop('disabled', !getSwappableColumnRow(selected));
        $('#btn-delete-section').prop('disabled', !(selected && selected.get && selected.get('type') === 'nl-section'));
    }

    function deleteSelectedSection() {
        var selected = editor && editor.getSelected && editor.getSelected();
        if (!selected || !selected.get) {
            return false;
        }
        var section = null;
        if (selected.get('type') === 'nl-section') {
            section = selected;
        } else if (isSectionBody(selected) || parentIsSectionChrome(selected)) {
            section = findAncestorSection(selected) || (selected.get('type') === 'nl-section' ? selected : null);
        }
        if (!section || typeof section.remove !== 'function') {
            return false;
        }
        section.remove();
        updateMoveButtons(null);
        return true;
    }

    function moveSelectedRow(direction) {
        var row = getMovableRow(editor.getSelected());
        if (!row) {
            return false;
        }
        var state = rowMoveState(row);
        var swap = state.index + direction;
        if (state.index < 0 || swap < 0 || swap >= state.reals.length) {
            return false;
        }
        var ordered = state.reals.slice();
        var tmp = ordered[state.index];
        ordered[state.index] = ordered[swap];
        ordered[swap] = tmp;
        var container = rowMoveContainer(row);
        syncingRowGaps = true;
        try {
            wrapperChildList(container).forEach(function(c) {
                if (isRowGap(c)) {
                    c.remove();
                }
            });
            ordered.forEach(function(c, i) {
                if (c && typeof c.move === 'function') {
                    c.move(container, { at: i });
                }
            });
        } finally {
            syncingRowGaps = false;
        }
        syncRowGaps();
        editor.select(row);
        updateMoveButtons(row);
        return true;
    }

    function setCanvasDragging(on) {
        var doc = editor && editor.Canvas && editor.Canvas.getDocument && editor.Canvas.getDocument();
        if (!doc || !doc.body) {
            return;
        }
        if (on) {
            doc.body.classList.add('pta-nl-dragging');
        } else {
            doc.body.classList.remove('pta-nl-dragging');
        }
    }

    function setupColumnFramework() {
        if (!editor) {
            return;
        }
        editor.on('load', function() {
            window.setTimeout(function() {
                hoistEscapedBlocksIntoCanvas();
                syncRowGaps();
            }, 0);
        });
        editor.on('component:add', function(component) {
            if (syncingRowGaps || !component || isRowGap(component)) {
                return;
            }
            var parent = component.parent && component.parent();
            if (parent && isRowGap(parent)) {
                var dest = parent.parent && parent.parent();
                var at = typeof parent.index === 'function' ? parent.index() : 0;
                hoistTo(component, dest, at);
            } else if (parent && isSectionHint(parent)) {
                var hintBody = parent.parent && parent.parent();
                hoistTo(component, hintBody);
            } else if (parentIsSectionChrome(parent) && !isSectionInternal(component)) {
                var chromeSection = parent.get('type') === 'nl-section'
                    ? parent
                    : findAncestorSection(parent);
                var body = findSectionBodyChild(chromeSection);
                if (body) {
                    hoistTo(component, body);
                }
            }
            if (component.get('type') === 'nl-columns') {
                window.setTimeout(function() {
                    hoistNestedColumns(component);
                }, 0);
            }
            if (component.get('type') === 'nl-section') {
                window.setTimeout(function() {
                    hoistNestedSection(component);
                }, 0);
            }
            window.setTimeout(function() {
                hoistNowNext(component);
                hoistEscapedBlocksIntoCanvas();
            }, 0);
            clearSectionHint(component);
            constrainColumnImage(component);
            window.setTimeout(syncRowGaps, 0);
        });
        editor.on('component:remove', function(component) {
            if (syncingRowGaps || isRowGap(component)) {
                return;
            }
            window.setTimeout(syncRowGaps, 0);
        });
        editor.on('block:drag:start', function() {
            setCanvasDragging(true);
        });
        editor.on('component:drag:start', function() {
            setCanvasDragging(true);
        });
        editor.on('block:drag:stop', function(component) {
            setCanvasDragging(false);
            if (component && component.get('type') === 'nl-columns') {
                hoistNestedColumns(component);
            }
            if (component && component.get('type') === 'nl-section') {
                hoistNestedSection(component);
            }
            hoistNowNext(component);
            hoistEscapedBlocksIntoCanvas();
            emptyGapsIntoWrapper();
            window.setTimeout(syncRowGaps, 0);
        });
        editor.on('component:drag:end', function(component) {
            setCanvasDragging(false);
            hoistNowNext(component);
            hoistEscapedBlocksIntoCanvas();
            emptyGapsIntoWrapper();
            window.setTimeout(syncRowGaps, 0);
        });
        var hasMoveCmd = false;
        try {
            hasMoveCmd = !!(editor.Commands && editor.Commands.get && editor.Commands.get('pta-move-row-up'));
        } catch (e) { hasMoveCmd = false; }
        editor.on('component:update:src', function(component) {
            constrainColumnImage(component);
        });
        if (editor.Commands && !hasMoveCmd) {
            editor.Commands.add('pta-move-row-up', {
                run: function() { moveSelectedRow(-1); }
            });
            editor.Commands.add('pta-move-row-down', {
                run: function() { moveSelectedRow(1); }
            });
        }
        var hasSwapCmd = false;
        try {
            hasSwapCmd = !!(editor.Commands && editor.Commands.get && editor.Commands.get('pta-swap-columns'));
        } catch (e2) { hasSwapCmd = false; }
        if (editor.Commands && !hasSwapCmd) {
            editor.Commands.add('pta-swap-columns', {
                run: function() { cycleSelectedColumns(); }
            });
        }
        var hasDeleteSectionCmd = false;
        try {
            hasDeleteSectionCmd = !!(editor.Commands && editor.Commands.get && editor.Commands.get('pta-delete-section'));
        } catch (e3) { hasDeleteSectionCmd = false; }
        if (editor.Commands && !hasDeleteSectionCmd) {
            editor.Commands.add('pta-delete-section', {
                run: function() { deleteSelectedSection(); }
            });
        }
    }

    var NL_TYPO_PROPS = {
        'font-family': 1,
        'font-size': 1,
        'font-weight': 1,
        'letter-spacing': 1,
        color: 1,
        'line-height': 1,
        'text-align': 1,
        'text-decoration': 1,
        'font-style': 1
    };

    function applyComponentStyle(comp, style) {
        if (!comp || !style) {
            return;
        }
        if (typeof comp.addStyle === 'function') {
            comp.addStyle(style);
        } else if (typeof comp.setStyle === 'function') {
            var cur = comp.getStyle() || {};
            Object.keys(style).forEach(function(k) {
                cur[k] = style[k];
            });
            comp.setStyle(cur);
        }
    }

    /**
     * Style Manager writes to the selected component. Text lives on
     * inner p/h1 tags that already have inline fonts, so a table-level
     * change is invisible. Push typography onto every text descendant
     * and keep spacing on the block cell only.
     */
    function applyBlockStyle(comp, name, val) {
        if (!comp || !name || val == null || val === '') {
            return;
        }
        var root = findTextBlockStyleRoot(comp) || comp;
        var style = {};
        style[name] = val;
        applyComponentStyle(root, style);
        if (!NL_TYPO_PROPS[name]) {
            return;
        }
        collectTextTargets(root, [], 0).forEach(function(textComp) {
            applyComponentStyle(textComp, style);
        });
    }

    function setupStyleApply() {
        if (!editor) {
            return;
        }
        editor.on('style:property:update', function(prop) {
            var comp = editor.getSelected();
            if (!comp || !prop) {
                return;
            }
            var name = (prop.get && prop.get('property')) || (prop.getName && prop.getName()) || '';
            var val = prop.get && prop.get('value');
            applyBlockStyle(comp, name, val);
        });
    }

    /**
     * Button tables used to be recognised only when the label was still
     * "Click Here". Custom labels then parsed as a plain table, and the
     * email-button defaults (button_url: '#') overwrote a saved href on
     * reload. Detect the block itself and keep the <a href> as source of truth.
     */
    function isNewsletterButtonTable(el) {
        if (!el || !el.tagName || el.tagName !== 'TABLE') {
            return false;
        }
        if (el.classList && el.classList.contains('nl-button')) {
            return true;
        }
        var links = el.querySelectorAll ? el.querySelectorAll('a') : [];
        if (links.length !== 1) {
            return false;
        }
        var a = links[0];
        if (a.querySelector && a.querySelector('img')) {
            return false;
        }
        var style = String(a.getAttribute('style') || '').toLowerCase();
        var looksPadded = style.indexOf('padding') !== -1 &&
            (style.indexOf('inline-block') !== -1 || style.indexOf('display: block') !== -1 || style.indexOf('display:block') !== -1);
        if (!looksPadded) {
            return false;
        }
        var td = a.parentElement;
        while (td && td.tagName !== 'TD') {
            td = td.parentElement;
        }
        if (!td) {
            return false;
        }
        var tdStyle = String(td.getAttribute('style') || '').toLowerCase();
        return !!(td.getAttribute('bgcolor') || tdStyle.indexOf('background') !== -1);
    }

    function buttonLinkHref(linkComp) {
        if (!linkComp) {
            return '';
        }
        var attrs = linkComp.getAttributes ? linkComp.getAttributes() : {};
        return String(attrs.href || '');
    }

    function firstButtonLink(comp) {
        if (!comp) {
            return null;
        }
        var found = [];
        if (comp.findType) {
            found = comp.findType('link') || [];
        }
        if ((!found || !found.length) && comp.find) {
            found = comp.find('a') || [];
        }
        return found && found.length ? found[0] : null;
    }

    function syncButtonFromLink(comp) {
        var link = firstButtonLink(comp);
        if (!link) {
            return;
        }
        var href = buttonLinkHref(link);
        var stored = String(comp.get('button_url') || '');
        if (href && href !== '#' && (!stored || stored === '#' || stored !== href)) {
            comp.set('button_url', href, { silent: true });
        }
        var text = '';
        if (link.getEl && link.getEl()) {
            text = String(link.getEl().textContent || '').replace(/\s+/g, ' ').trim();
        } else if (typeof link.get === 'function' && typeof link.get('content') === 'string') {
            text = link.get('content').replace(/\s+/g, ' ').trim();
        }
        var storedText = String(comp.get('button_text') || '');
        if (text && storedText && storedText === 'Click Here' && text !== 'Click Here') {
            comp.set('button_text', text, { silent: true });
        }
    }

    function wrapImgHrefInHtml(html) {
        if (!html || typeof html !== 'string') {
            return html;
        }
        return html.replace(/<img\b([^>]*)>/gi, function(tag, attrs) {
            var hrefMatch = attrs.match(/\bhref\s*=\s*(["'])([^"']*)\1/i);
            if (!hrefMatch) {
                return tag;
            }
            var url = String(hrefMatch[2] || '').trim();
            if (!url || url === '#') {
                return tag;
            }
            var cleaned = attrs.replace(/\s*href\s*=\s*(["'])([^"']*)\1/i, '');
            return '<a href="' + url + '" target="_blank" style="text-decoration:none;border:0;"><img' + cleaned + '></a>';
        });
    }

    var applyingImageLinks = false;

    function imageHrefValue(comp) {
        if (!comp || !comp.get) {
            return '';
        }
        var prop = comp.get('href');
        if (typeof prop === 'string' && prop.trim()) {
            return prop.trim();
        }
        var attrs = comp.getAttributes ? comp.getAttributes() : {};
        return String(attrs.href || '').trim();
    }

    function parentAnchor(comp) {
        var parent = comp && comp.parent ? comp.parent() : null;
        if (parent && String(parent.get('tagName') || '').toLowerCase() === 'a') {
            return parent;
        }
        return null;
    }

    function applyImageLink(imgComp) {
        if (applyingImageLinks || !imgComp || !imgComp.get) {
            return;
        }
        var type = imgComp.get('type');
        if (type !== 'image' && type !== 'email-image') {
            return;
        }
        var href = imageHrefValue(imgComp);
        var anchor = parentAnchor(imgComp);
        if (anchor) {
            var existing = String((anchor.getAttributes() || {}).href || '').trim();
            if (!href || href === '#') {
                if (existing && existing !== '#') {
                    imgComp.set('href', existing, { silent: true });
                }
                return;
            }
            if (existing !== href) {
                anchor.addAttributes({ href: href, target: '_blank' });
            }
            return;
        }
        if (!href || href === '#') {
            return;
        }
        var parent = imgComp.parent && imgComp.parent();
        if (!parent || !parent.components) {
            return;
        }
        applyingImageLinks = true;
        try {
            var at = typeof imgComp.index === 'function' ? imgComp.index() : 0;
            var json = imgComp.toJSON();
            if (json.attributes) {
                delete json.attributes.href;
            }
            parent.components().remove(imgComp);
            var added = parent.components().add({
                type: 'link',
                tagName: 'a',
                attributes: {
                    href: href,
                    target: '_blank',
                    style: 'text-decoration: none; border: 0;'
                },
                components: [json]
            }, { at: at });
            var newImg = added && added.findType ? (added.findType('image') || [])[0] : null;
            if (newImg && typeof newImg.set === 'function') {
                newImg.set('href', href, { silent: true });
            }
        } finally {
            applyingImageLinks = false;
        }
    }

    function applyAllImageLinks() {
        if (!editor || !editor.getWrapper) {
            return;
        }
        var wrapper = editor.getWrapper();
        if (!wrapper || !wrapper.findType) {
            return;
        }
        var images = wrapper.findType('image') || [];
        images.forEach(function(img) {
            applyImageLink(img);
        });
    }

    function syncAllEmailButtons() {
        if (!editor || !editor.getWrapper) {
            return;
        }
        var wrapper = editor.getWrapper();
        if (!wrapper || !wrapper.findType) {
            return;
        }
        var buttons = wrapper.findType('email-button') || [];
        buttons.forEach(function(btn) {
            syncButtonFromLink(btn);
        });
    }

    /**
     * Register custom component types with traits (settings)
     */
    function registerComponentTypes() {
        if (!editor) return;
        
        var dc = editor.DomComponents;
        
        // Register custom "media-library" trait type for image selection
        editor.TraitManager.addType('media-library-button', {
            createInput: function(opts) {
                var el = document.createElement('div');
                el.innerHTML = '<button type="button" class="button media-library-btn" style="width:100%;text-align:center;padding:8px 12px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">' +
                    '<span class="dashicons dashicons-format-image" style="font-size:16px;width:16px;height:16px;margin-right:6px;vertical-align:middle;"></span>' +
                    'Browse Media Library</button>';
                var btn = el.querySelector('button');
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var component = editor.getSelected();
                    if (component) {
                        openMediaLibrary({ target: component });
                    }
                });
                return el;
            },
            onUpdate: function() {},
            onEvent: function() {}
        });

        editor.TraitManager.addType('column-widths', {
            createInput: function() {
                var el = document.createElement('div');
                el.className = 'pta-nl-col-widths';
                return el;
            },
            onUpdate: function(opts) {
                var elInput = (opts && opts.elInput) || this.elInput || this.el;
                var component = (opts && opts.component) || this.target || editor.getSelected();
                if (!elInput || !component) {
                    return;
                }
                renderColumnWidthSliders(elInput, component);
            },
            onEvent: function() {}
        });

        dc.addType('nl-section', {
            isComponent: function(el) {
                return el && el.tagName === 'TABLE' && el.classList && el.classList.contains('nl-section');
            },
            model: {
                defaults: {
                    tagName: 'table',
                    droppable: false,
                    draggable: true,
                    copyable: true,
                    removable: true,
                    name: 'Section',
                    attributes: {
                        class: 'nl-section',
                        width: '100%',
                        cellpadding: '0',
                        cellspacing: '0',
                        border: '0'
                    },
                    toolbar: [
                        {
                            label: '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M7 14l5-6 5 6z"/></svg>',
                            attributes: { title: 'Move section up' },
                            command: 'pta-move-row-up'
                        },
                        {
                            label: '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M7 10l5 6 5-6z"/></svg>',
                            attributes: { title: 'Move section down' },
                            command: 'pta-move-row-down'
                        },
                        {
                            attributes: { class: 'fa fa-arrows' },
                            command: 'tlb-move'
                        },
                        {
                            attributes: { class: 'fa fa-clone' },
                            command: 'tlb-clone'
                        },
                        {
                            attributes: { class: 'fa fa-trash-o', title: 'Delete section' },
                            command: 'pta-delete-section'
                        }
                    ]
                }
            }
        });

        dc.addType('nl-section-body', {
            isComponent: function(el) {
                return el && el.tagName === 'TD' && el.classList && el.classList.contains('nl-section-body');
            },
            model: {
                defaults: {
                    tagName: 'td',
                    draggable: false,
                    copyable: false,
                    removable: false,
                    selectable: false,
                    hoverable: false,
                    droppable: ':not(.nl-section)',
                    traits: [],
                    style: {
                        padding: '0',
                        'vertical-align': 'top'
                    }
                }
            }
        });

        dc.addType('nl-section-hint', {
            isComponent: function(el) {
                return el && el.classList && el.classList.contains('nl-section-hint');
            },
            model: {
                defaults: {
                    tagName: 'div',
                    droppable: ':not(.nl-section)',
                    draggable: false,
                    copyable: false,
                    removable: false,
                    selectable: false,
                    hoverable: true,
                    highlightable: true,
                    layerable: false,
                    attributes: { class: 'nl-section-hint' }
                }
            }
        });

        dc.addType('nl-columns', {
            isComponent: function(el) {
                return el && el.tagName === 'TABLE' && el.classList && el.classList.contains('nl-stack-cols');
            },
            model: {
                defaults: {
                    tagName: 'table',
                    droppable: false,
                    draggable: true,
                    copyable: true,
                    removable: true,
                    attributes: {
                        class: 'nl-stack-cols',
                        width: '100%',
                        cellpadding: '0',
                        cellspacing: '0',
                        border: '0'
                    },
                    traits: [
                        {
                            type: 'column-widths',
                            name: 'column_widths',
                            label: 'Column widths'
                        }
                    ],
                    toolbar: [
                        {
                            label: '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M7 14l5-6 5 6z"/></svg>',
                            attributes: { title: 'Move row up' },
                            command: 'pta-move-row-up'
                        },
                        {
                            label: '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M7 10l5 6 5-6z"/></svg>',
                            attributes: { title: 'Move row down' },
                            command: 'pta-move-row-down'
                        },
                        {
                            label: '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M7 7h11l-3-3 1.4-1.4L22 8l-5.6 5.4L15 12l3-3H7V7zm10 10H6l3 3-1.4 1.4L2 16l5.6-5.4L9 12l-3 3h11v2z"/></svg>',
                            attributes: { title: 'Swap columns' },
                            command: 'pta-swap-columns'
                        },
                        {
                            attributes: { class: 'fa fa-arrows' },
                            command: 'tlb-move'
                        },
                        {
                            attributes: { class: 'fa fa-clone' },
                            command: 'tlb-clone'
                        },
                        {
                            attributes: { class: 'fa fa-trash-o' },
                            command: 'tlb-delete'
                        }
                    ]
                }
            }
        });

        dc.addType('nl-column', {
            isComponent: function(el) {
                return el && el.tagName === 'TD' && el.classList
                    && (el.classList.contains('nl-column') || el.classList.contains('nl-stack-col'));
            },
            model: {
                defaults: {
                    tagName: 'td',
                    draggable: false,
                    copyable: false,
                    removable: false,
                    droppable: ':not(.nl-stack-cols):not(.nl-section)',
                    traits: [],
                    style: {
                        padding: '10px',
                        'vertical-align': 'top'
                    }
                }
            }
        });

        dc.addType('nl-row-gap', {
            isComponent: function(el) {
                return el && el.classList && el.classList.contains('nl-row-gap');
            },
            model: {
                defaults: {
                    tagName: 'div',
                    droppable: true,
                    draggable: false,
                    copyable: false,
                    removable: false,
                    selectable: false,
                    hoverable: true,
                    highlightable: true,
                    layerable: false,
                    badgable: false,
                    attributes: { class: 'nl-row-gap', 'aria-hidden': 'true' }
                },
                toHTML: function() {
                    return '';
                }
            }
        });
        
        // === PTA DIRECTORY COMPONENT ===
        dc.addType('pta-directory', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.innerHTML.indexOf('[pta-roles-directory') > -1;
            },
            model: {
                defaults: {
                    tagName: 'table',
                    draggable: true,
                    droppable: false,
                    attributes: { class: 'pta-shortcode-block' },
                    traits: [
                        {
                            type: 'select',
                            label: 'Department',
                            name: 'department',
                            options: [
                                { id: 'all', name: 'All Departments' },
                                { id: 'executive', name: 'Executive Board' },
                                { id: 'communications', name: 'Communications' },
                                { id: 'fundraising', name: 'Fundraising' },
                                { id: 'programs', name: 'Programs' },
                                { id: 'volunteers', name: 'Volunteers' }
                            ],
                            changeProp: 1
                        },
                        {
                            type: 'select',
                            label: 'Columns',
                            name: 'columns',
                            options: [
                                { id: '1', name: '1 Column' },
                                { id: '2', name: '2 Columns' },
                                { id: '3', name: '3 Columns' }
                            ],
                            changeProp: 1
                        },
                        {
                            type: 'checkbox',
                            label: 'Show Empty Roles',
                            name: 'show_empty',
                            changeProp: 1
                        }
                    ],
                    department: 'all',
                    columns: '2',
                    show_empty: true
                },
                init: function() {
                    this.on('change:department change:columns change:show_empty', this.updateShortcode);
                },
                updateShortcode: function() {
                    var dept = this.get('department');
                    var cols = this.get('columns');
                    var showEmpty = this.get('show_empty');
                    var shortcode = '[pta-roles-directory';
                    if (dept !== 'all') shortcode += ' department="' + dept + '"';
                    shortcode += ' columns="' + cols + '"';
                    if (!showEmpty) shortcode += ' show_empty="false"';
                    shortcode += ']';
                    
                    var inner = this.components().at(0);
                    if (inner) {
                        var td = inner.find('td')[0];
                        if (td) {
                            td.find('p')[0].components(shortcode);
                        }
                    }
                }
            }
        });
        
        // === PTA OPEN POSITIONS COMPONENT ===
        dc.addType('pta-open-positions', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.innerHTML.indexOf('[pta-open-positions') > -1;
            },
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'number',
                            label: 'Max Positions',
                            name: 'limit',
                            min: 1,
                            max: 20,
                            changeProp: 1
                        },
                        {
                            type: 'select',
                            label: 'Department',
                            name: 'department',
                            options: [
                                { id: 'all', name: 'All Departments' },
                                { id: 'executive', name: 'Executive Board' },
                                { id: 'communications', name: 'Communications' },
                                { id: 'fundraising', name: 'Fundraising' },
                                { id: 'programs', name: 'Programs' },
                                { id: 'volunteers', name: 'Volunteers' }
                            ],
                            changeProp: 1
                        }
                    ],
                    limit: 5,
                    department: 'all'
                }
            }
        });
        
        // === LATEST POSTS COMPONENT ===
        dc.addType('latest-posts', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.innerHTML.indexOf('Latest News') > -1;
            },
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'number',
                            label: 'Number of Posts',
                            name: 'post_count',
                            min: 1,
                            max: 10,
                            changeProp: 1
                        },
                        {
                            type: 'text',
                            label: 'Category (slug)',
                            name: 'category',
                            placeholder: 'e.g., news, events',
                            changeProp: 1
                        },
                        {
                            type: 'checkbox',
                            label: 'Show Featured Image',
                            name: 'show_image',
                            changeProp: 1
                        },
                        {
                            type: 'checkbox',
                            label: 'Show Excerpt',
                            name: 'show_excerpt',
                            changeProp: 1
                        },
                        {
                            type: 'number',
                            label: 'Excerpt Length',
                            name: 'excerpt_length',
                            min: 10,
                            max: 100,
                            changeProp: 1
                        }
                    ],
                    post_count: 3,
                    category: '',
                    show_image: true,
                    show_excerpt: true,
                    excerpt_length: 20
                }
            }
        });
        
        // === HTML BLOCK COMPONENT ===
        dc.addType('html-block', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.innerHTML.indexOf('Double-click to edit HTML') > -1;
            },
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'text',
                            label: 'Custom HTML',
                            name: 'custom_html',
                            changeProp: 1
                        }
                    ],
                    custom_html: '<!-- Your custom HTML here -->'
                }
            },
            view: {
                events: {
                    dblclick: 'openCodeEditor'
                },
                openCodeEditor: function() {
                    var model = this.model;
                    var content = model.get('custom_html') || '';
                    
                    editor.Modal.setTitle('Edit Custom HTML');
                    editor.Modal.setContent(`
                        <div style="padding: 15px;">
                            <textarea id="html-code-editor" style="width: 100%; height: 300px; font-family: monospace; font-size: 13px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">${escapeHtml(content)}</textarea>
                            <div style="margin-top: 15px; text-align: right;">
                                <button id="save-html-code" class="button button-primary">Save HTML</button>
                            </div>
                        </div>
                    `);
                    editor.Modal.open();
                    
                    $('#save-html-code').on('click', function() {
                        var newHtml = $('#html-code-editor').val();
                        model.set('custom_html', newHtml);
                        
                        // Update the visual representation
                        var inner = model.components().at(0);
                        if (inner) {
                            var td = inner.find('td')[0];
                            if (td) {
                                td.find('div')[0].components(newHtml || '<div style="padding: 20px; background: #f5f5f5; border: 1px dashed #ccc; text-align: center; color: #666;">Custom HTML Block</div>');
                            }
                        }
                        
                        editor.Modal.close();
                    });
                }
            }
        });
        
        // === BUTTON COMPONENT ===
        dc.addType('email-button', {
            isComponent: function(el) {
                if (!isNewsletterButtonTable(el)) {
                    return false;
                }
                var a = el.querySelector('a');
                var href = a ? (a.getAttribute('href') || '') : '';
                var text = a ? String(a.textContent || '').replace(/\s+/g, ' ').trim() : '';
                return {
                    type: 'email-button',
                    button_url: href || '#',
                    button_text: text || 'Click Here'
                };
            },
            model: {
                defaults: {
                    tagName: 'table',
                    attributes: {
                        class: 'nl-button',
                        cellpadding: '0',
                        cellspacing: '0',
                        border: '0',
                        align: 'center'
                    },
                    traits: [
                        {
                            type: 'text',
                            label: 'Button Text',
                            name: 'button_text',
                            changeProp: 1
                        },
                        {
                            type: 'text',
                            label: 'Link URL',
                            name: 'button_url',
                            placeholder: 'https://',
                            changeProp: 1
                        },
                        {
                            type: 'color',
                            label: 'Button Color',
                            name: 'button_color',
                            changeProp: 1
                        },
                        {
                            type: 'color',
                            label: 'Text Color',
                            name: 'text_color',
                            changeProp: 1
                        }
                    ],
                    button_text: 'Click Here',
                    button_url: '#',
                    button_color: '#2271b1',
                    text_color: '#ffffff'
                },
                init: function() {
                    var self = this;
                    this.on('change:button_text change:button_url change:button_color change:text_color', this.updateButtonFromTraits);
                    this.on('change:components', function() {
                        syncButtonFromLink(self);
                    });
                    syncButtonFromLink(this);
                },
                updateButtonFromTraits: function() {
                    var link = firstButtonLink(this);
                    if (!link) {
                        return;
                    }
                    var currentHref = buttonLinkHref(link);
                    var href = this.get('button_url');
                    if ((!href || href === '#') && currentHref && currentHref !== '#') {
                        syncButtonFromLink(this);
                        return;
                    }
                    var text = this.get('button_text');
                    var currentText = '';
                    if (link.getEl && link.getEl()) {
                        currentText = String(link.getEl().textContent || '').replace(/\s+/g, ' ').trim();
                    }
                    if (text === 'Click Here' && currentText && currentText !== 'Click Here') {
                        syncButtonFromLink(this);
                        return;
                    }
                    if (typeof text === 'string') {
                        link.components(text);
                    }
                    if (typeof href === 'string' && href !== '') {
                        link.addAttributes({ href: href });
                    }
                    var style = {};
                    if (this.get('button_color')) {
                        style['background-color'] = this.get('button_color');
                    }
                    if (this.get('text_color')) {
                        style.color = this.get('text_color');
                    }
                    if (Object.keys(style).length) {
                        link.addStyle(style);
                    }
                }
            }
        });
        
        // === IMAGE COMPONENT ===
        // Extend the built-in 'image' type so any <img> gets the media library button
        dc.addType('image', {
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'media-library-button',
                            label: 'Image'
                        },
                        {
                            type: 'text',
                            label: 'Image URL',
                            name: 'src',
                            changeProp: 1
                        },
                        {
                            type: 'text',
                            label: 'Alt Text',
                            name: 'alt',
                            placeholder: 'Describe the image'
                        },
                        {
                            type: 'text',
                            label: 'Link URL',
                            name: 'href',
                            placeholder: 'https://',
                            changeProp: 1
                        },
                        {
                            type: 'number',
                            label: 'Width',
                            name: 'width'
                        }
                    ]
                }
            }
        });
        
        // Backward compatibility for saved newsletters that reference 'email-image'
        dc.addType('email-image', {
            extend: 'default'
        });
        
        // === SHORTCODE COMPONENT ===
        dc.addType('wp-shortcode', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.innerHTML.indexOf('[your_shortcode]') > -1;
            },
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'text',
                            label: 'Shortcode',
                            name: 'shortcode',
                            placeholder: '[your_shortcode attr="value"]',
                            changeProp: 1
                        }
                    ],
                    shortcode: '[your_shortcode]'
                },
                init: function() {
                    this.on('change:shortcode', this.updateDisplay);
                },
                updateDisplay: function() {
                    var shortcode = this.get('shortcode') || '[your_shortcode]';
                    var inner = this.components().at(0);
                    if (inner) {
                        var td = inner.find('td')[0];
                        if (td) {
                            var p = td.find('p')[0];
                            if (p) p.components(shortcode);
                        }
                    }
                }
            }
        });
        
        // === SPACER COMPONENT ===
        dc.addType('email-spacer', {
            isComponent: function(el) {
                return el.tagName === 'TABLE' && el.querySelector('td[style*="height"]') && el.innerHTML.indexOf('&nbsp;') > -1;
            },
            model: {
                defaults: {
                    traits: [
                        {
                            type: 'number',
                            label: 'Height (px)',
                            name: 'spacer_height',
                            min: 5,
                            max: 200,
                            changeProp: 1
                        }
                    ],
                    spacer_height: 30
                }
            }
        });
    }
    
    /**
     * Visible text blocks (headlines, paragraphs) — not images or layout tables.
     */
    function isEditableTextComponent(component) {
        if (!component || !component.get) {
            return false;
        }
        var type = component.get('type');
        if (type === 'image' || type === 'email-image' || type === 'wrapper' || type === 'nl-columns' || type === 'nl-column') {
            return false;
        }
        if (type === 'text' || type === 'textnode') {
            return true;
        }
        var tag = String(component.get('tagName') || '').toLowerCase();
        return ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'strong', 'em', 'li', 'a'].indexOf(tag) !== -1;
    }

    function componentInnerHtml(component) {
        var el = component.getEl && component.getEl();
        if (el) {
            return el.innerHTML;
        }
        var content = component.get('content');
        return typeof content === 'string' ? content : '';
    }

    function collectTextTargets(component, acc, depth) {
        acc = acc || [];
        depth = depth || 0;
        if (!component || depth > 8) {
            return acc;
        }
        if (isEditableTextComponent(component)) {
            acc.push(component);
            return acc;
        }
        var type = component.get && component.get('type');
        if (type === 'image' || type === 'email-image') {
            return acc;
        }
        var children = component.components && component.components();
        if (!children || !children.forEach) {
            return acc;
        }
        children.forEach(function(child) {
            collectTextTargets(child, acc, depth + 1);
        });
        return acc;
    }

    /**
     * Heading / text blocks are tables. Clicking the block selects the
     * table; the words live on the inner h1/p. Prefer the inner text
     * for RTE, but keep Styles on the wrapping cell so padding/font
     * apply to the whole block.
     */
    function findTextEditTarget(component) {
        if (!component) {
            return null;
        }
        if (isColumnFrame(component) || isRowGap(component)) {
            return null;
        }
        if (isEditableTextComponent(component)) {
            return component;
        }
        var targets = collectTextTargets(component, [], 0);
        return targets.length >= 1 ? targets[0] : null;
    }

    function walkChildTds(component, acc, depth) {
        acc = acc || [];
        depth = depth || 0;
        if (!component || depth > 6) {
            return acc;
        }
        var tag = String(component.get('tagName') || '').toLowerCase();
        if (tag === 'td') {
            acc.push(component);
            return acc;
        }
        var children = component.components && component.components();
        if (children && children.forEach) {
            children.forEach(function(child) {
                walkChildTds(child, acc, depth + 1);
            });
        }
        return acc;
    }

    function blockHasTypographicText(component) {
        var acc = [];
        if (isEditableTextComponent(component)) {
            acc.push(component);
        } else {
            collectTextTargets(component, acc, 0);
        }
        return acc.some(function(t) {
            var tag = String(t.get('tagName') || '').toLowerCase();
            return t.get('type') === 'text' || ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'li', 'strong', 'em'].indexOf(tag) !== -1;
        });
    }

    /**
     * The email-styled cell around a text/heading block. Spacing
     * belongs here, not on the first <p> or a text node.
     */
    function findTextBlockStyleRoot(component) {
        if (!component || isColumnFrame(component) || isRowGap(component)) {
            return null;
        }
        if (!blockHasTypographicText(component)) {
            return null;
        }
        var cur = component;
        while (cur) {
            if (isColumnFrame(cur) || isRowGap(cur)) {
                return null;
            }
            var tag = String(cur.get('tagName') || '').toLowerCase();
            var type = cur.get('type');
            if (tag === 'td' && type !== 'nl-column' && type !== 'nl-section-body') {
                return cur;
            }
            cur = cur.parent ? cur.parent() : null;
        }
        var tds = walkChildTds(component, [], 0).filter(function(td) {
            return !isColumnFrame(td);
        });
        return tds.length === 1 ? tds[0] : null;
    }

    function traitNames(component) {
        var traits = component.getTraits ? component.getTraits() : [];
        var names = [];
        if (traits && traits.forEach) {
            traits.forEach(function(trait) {
                names.push(trait.get('name'));
            });
        } else if (traits && traits.length) {
            for (var i = 0; i < traits.length; i++) {
                names.push(traits[i].get ? traits[i].get('name') : '');
            }
        }
        return names;
    }

    function settingsTextarea() {
        var $panel = $('#traits-container');
        var $byLabel = $panel.find('.gjs-trt-trait').filter(function() {
            return $.trim($(this).find('.gjs-label').first().text()) === 'Text';
        }).find('textarea').first();
        if ($byLabel.length) {
            return $byLabel;
        }
        return $panel.find('textarea').first();
    }

    /**
     * Copy canvas HTML into the Settings → Text box without re-rendering
     * the trait panel (a re-render steals the RTE caret).
     */
    function isTextSettingsHost(component) {
        if (!component) {
            return false;
        }
        return isEditableTextComponent(component) || !!findTextBlockStyleRoot(component);
    }

    function syncSettingsTextFromCanvas(component) {
        if (!isTextSettingsHost(component)) {
            return;
        }
        var html = componentInnerHtml(component);
        if (component.get('content') !== html) {
            component.set('content', html, { silent: true });
        }
        var $field = settingsTextarea();
        if ($field.length && $field.val() !== html) {
            $field.val(html);
        }
    }

    function applyTextToCanvas(component, val) {
        if (!component || typeof val !== 'string' || component._ptaApplyingText) {
            return;
        }
        component._ptaApplyingText = true;
        try {
            component.components(val);
        } finally {
            component._ptaApplyingText = false;
        }
    }

    function enableCanvasTextEdit(textComp) {
        if (!textComp) {
            return;
        }
        var view = textComp.getView && textComp.getView();
        if (view && typeof view.onActive === 'function') {
            view.onActive();
            return;
        }
        if (editor.RichTextEditor && view && view.el && typeof editor.RichTextEditor.enable === 'function') {
            try {
                editor.RichTextEditor.enable(view.el, textComp);
            } catch (e) { /* RTE optional */ }
        }
    }

    /**
     * GrapesJS ships every component with an HTML `title` attribute trait.
     * Editors type the headline there and the canvas never changes. Bind a
     * real Text trait to the component's inner HTML instead.
     */
    function ensureTextContentTrait(component) {
        if (!isTextSettingsHost(component)) {
            return;
        }

        var names = traitNames(component);

        if (names.indexOf('title') !== -1 && component.removeTrait) {
            component.removeTrait('title');
        }

        if (names.indexOf('content') === -1 && component.addTrait) {
            component.addTrait({
                type: 'textarea',
                name: 'content',
                label: 'Text',
                changeProp: 1
            }, { at: 1 });
        }

        var html = componentInnerHtml(component);
        if (html && component.get('content') !== html) {
            component.set('content', html, { silent: true });
        }

        if (!component._ptaTextBound) {
            component._ptaTextBound = true;
            component.on('change:content', function() {
                if (component._ptaApplyingText) {
                    return;
                }
                var val = component.get('content');
                applyTextToCanvas(component, val);
            });
        }
    }

    /**
     * Setup component selection handling for Settings panel
     */
    function setupComponentSelection() {
        if (!editor) return;

        var rteInputCleanup = null;
        var rteActive = false;

        editor.on('component:selected', function(component) {
            var traitsContainer = $('#traits-container');
            var placeholder = $('.settings-placeholder');

            if (!component) {
                placeholder.show();
                traitsContainer.hide();
                $('#styles-container').hide();
                $('#selected-element-name .element-name').text('No element selected');
                updateMoveButtons(null);
                return;
            }

            if (component.get('type') === 'nl-column') {
                var row = findAncestorColumns(component);
                if (row && row !== component) {
                    editor.select(row);
                    return;
                }
            }

            if (isSectionBody(component) || parentIsSectionChrome(component)) {
                var owningSection = component.get('type') === 'nl-section'
                    ? component
                    : findAncestorSection(component);
                if (owningSection && owningSection !== component) {
                    editor.select(owningSection);
                    return;
                }
            }

            var styleRoot = findTextBlockStyleRoot(component);
            if (styleRoot && styleRoot !== component) {
                styleRoot._ptaRteTarget = isEditableTextComponent(component)
                    ? component
                    : findTextEditTarget(component);
                editor.select(styleRoot);
                return;
            }

            var textTarget = component._ptaRteTarget || findTextEditTarget(component);
            component._ptaRteTarget = null;
            ensureTextContentTrait(component);

            if (editor.TraitManager && typeof editor.TraitManager.render === 'function') {
                editor.TraitManager.render();
            }
            placeholder.hide();
            var traits = component.get('traits');
            if (traits && traits.length > 0) {
                traitsContainer.show();
            } else {
                traitsContainer.hide();
            }
            $('#styles-container').show();

            updateElementIndicator(component);
            updateMoveButtons(getMovableRow(component));

            if (textTarget) {
                window.setTimeout(function() {
                    enableCanvasTextEdit(textTarget);
                    syncSettingsTextFromCanvas(component);
                }, 0);
            }
        });

        editor.on('rte:enable', function() {
            rteActive = true;
            var selected = editor.getSelected();
            if (!isTextSettingsHost(selected)) {
                return;
            }
            var textTarget = findTextEditTarget(selected) || selected;
            var el = textTarget.getEl && textTarget.getEl();
            if (!el) {
                return;
            }
            if (rteInputCleanup) {
                rteInputCleanup();
            }
            var onInput = function() {
                if (selected._ptaApplyingText) {
                    return;
                }
                syncSettingsTextFromCanvas(selected);
            };
            el.addEventListener('input', onInput);
            el.addEventListener('keyup', onInput);
            rteInputCleanup = function() {
                el.removeEventListener('input', onInput);
                el.removeEventListener('keyup', onInput);
                rteInputCleanup = null;
            };
        });

        editor.on('rte:disable', function() {
            rteActive = false;
            if (rteInputCleanup) {
                rteInputCleanup();
            }
            var selected = editor.getSelected();
            if (!isTextSettingsHost(selected)) {
                return;
            }
            syncSettingsTextFromCanvas(selected);
            if (editor.TraitManager && typeof editor.TraitManager.render === 'function') {
                editor.TraitManager.render();
            }
        });

        $(document).on('input.ptaNewsletterText', '#traits-container textarea', function() {
            var selected = editor.getSelected();
            if (!isTextSettingsHost(selected)) {
                return;
            }
            if (rteActive) {
                return;
            }
            applyTextToCanvas(selected, this.value);
            selected.set('content', this.value, { silent: true });
        });

        editor.on('component:deselected', function() {
            if (rteInputCleanup) {
                rteInputCleanup();
            }
            $('.settings-placeholder').show();
            $('#traits-container').hide();
            $('#styles-container').hide();
            $('#selected-element-name .element-name').text('No element selected');
            updateMoveButtons(null);
        });

        editor.on('component:dblclick', function(component) {
            if (!component) return;
            var el = component.view && component.view.el;
            if (!el) return;

            if (el.tagName === 'IMG') {
                openMediaLibrary({ target: component });
                return;
            }
            var textTarget = findTextEditTarget(component);
            if (textTarget) {
                if (textTarget !== component) {
                    editor.select(textTarget);
                }
                enableCanvasTextEdit(textTarget);
            }
        });
    }
    
    /**
     * Update the element indicator in Styles panel
     */
    function updateElementIndicator(component) {
        if (!component) return;
        
        var tagName = component.get('tagName') || 'element';
        var type = component.get('type') || '';
        var classes = component.getClasses().join(' ');
        
        // Build a readable name
        var name = tagName.toUpperCase();
        if (type && type !== 'default') {
            name = type.replace(/-/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
        }
        if (classes) {
            name += ' (' + classes.substring(0, 30) + (classes.length > 30 ? '...' : '') + ')';
        }
        
        $('#selected-element-name .element-name').text(name);
    }

    /**
     * Load initial content into editor
     */
    function loadInitialContent() {
        if (!editor || typeof newsletterEditorConfig === 'undefined') return;

        // Always prefer JSON content as it preserves GrapesJS state perfectly
        if (newsletterEditorConfig.initialContent) {
            try {
                var data = JSON.parse(newsletterEditorConfig.initialContent);
                if (data && Object.keys(data).length > 0) {
                    editor.loadProjectData(data);
                    window.setTimeout(function() {
                        syncAllEmailButtons();
                        applyAllImageLinks();
                    }, 0);
                    return;
                }
            } catch (e) {
                console.log('Could not parse JSON content, trying HTML');
            }
        }

        if (newsletterEditorConfig.initialHtml) {
            // Extract body content if this is a full HTML document
            var html = newsletterEditorConfig.initialHtml;
            
            // Check if it's a full document (contains <body>)
            var bodyMatch = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
            if (bodyMatch) {
                html = bodyMatch[1];
            }
            
            // Also check for doctype/html tags without body
            if (html.indexOf('<!DOCTYPE') === 0 || html.indexOf('<html') === 0) {
                // Try to extract content after </head> or after <html>
                var headEnd = html.indexOf('</head>');
                if (headEnd > -1) {
                    html = html.substring(headEnd + 7);
                }
                // Remove closing tags
                html = html.replace(/<\/html>/gi, '').replace(/<\/body>/gi, '');
            }
            
            // Strip any CSS text that leaked into the content
            html = html.replace(/^[\s\S]*?(?=<table|<div|<p|<h[1-6])/i, '');
            
            editor.setComponents(html);
            window.setTimeout(function() {
                syncAllEmailButtons();
                applyAllImageLinks();
            }, 0);
        } else {
            // Set default starter template
            editor.setComponents(`
                <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f4">
                    <tr>
                        <td align="center" style="padding: 20px;">
                            <table width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="max-width: 600px;">
                                <tr>
                                    <td align="center" bgcolor="#2271b1" style="padding: 30px 20px;">
                                        <h1 style="margin: 0; font-family: Arial, sans-serif; font-size: 24px; color: #ffffff;">
                                            Your Newsletter Title
                                        </h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 30px 20px; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333;">
                                        <p>Hello {{first_name}},</p>
                                        <p>Start creating your newsletter by dragging blocks from the left panel. You can add text, images, buttons, and more.</p>
                                        <p>Use Settings to customize colors, fonts, and spacing.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" bgcolor="#f8f9fa" style="padding: 20px; font-family: Arial, sans-serif; font-size: 12px; color: #666666;">
                                        <p style="margin: 0;">
                                            <a href="{{unsubscribe_url}}" style="color: #666666;">Unsubscribe</a> | 
                                            <a href="{{view_in_browser_url}}" style="color: #666666;">View in Browser</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            `);
        }
    }

    /**
     * Setup device preview buttons
     */
    function setupDeviceButtons() {
        $('.device-btn').on('click', function() {
            var device = $(this).data('device');
            $('.device-btn').removeClass('active');
            $(this).addClass('active');
            
            if (editor) {
                var deviceName = '';
                switch (device) {
                    case 'desktop':
                        deviceName = 'Desktop';
                        break;
                    case 'tablet':
                        deviceName = 'Tablet';
                        break;
                    case 'mobile':
                        deviceName = 'Mobile';
                        break;
                }
                
                editor.setDevice(deviceName);
                
                // Add data attribute for CSS targeting
                var frameWrapper = document.querySelector('.gjs-frame-wrapper');
                if (frameWrapper) {
                    frameWrapper.setAttribute('data-device', deviceName);
                }
                
                // Force canvas to recalculate scroll area after device change
                setTimeout(function() {
                    var canvas = editor.Canvas;
                    if (canvas && canvas.refresh) {
                        canvas.refresh();
                    }
                }, 100);
            }
        });
    }

    /**
     * Setup toolbar buttons (undo, redo, code)
     */
    function setupToolbarButtons() {
        // Undo
        $('#btn-undo').on('click', function() {
            if (editor) {
                editor.UndoManager.undo();
            }
        });
        
        // Redo
        $('#btn-redo').on('click', function() {
            if (editor) {
                editor.UndoManager.redo();
            }
        });

        $('#btn-row-up').on('click', function() {
            moveSelectedRow(-1);
        });
        $('#btn-row-down').on('click', function() {
            moveSelectedRow(1);
        });
        $('#btn-swap-cols').on('click', function() {
            cycleSelectedColumns();
        });
        $('#btn-delete-section').on('click', function() {
            deleteSelectedSection();
        });

        // View/Edit code
        $('#btn-code').on('click', function() {
            if (editor) {
                var html = editor.getHtml();
                var css = editor.getCss();
                
                // Create modal for code view
                var modal = editor.Modal;
                modal.setTitle('Email HTML Code');
                modal.setContent(`
                    <div style="padding: 10px;">
                        <h4>HTML</h4>
                        <textarea style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;">${escapeHtml(html)}</textarea>
                        <h4 style="margin-top: 15px;">CSS</h4>
                        <textarea style="width: 100%; height: 100px; font-family: monospace; font-size: 12px;">${escapeHtml(css)}</textarea>
                    </div>
                `);
                modal.open();
            }
        });
    }

    /**
     * Setup sidebar tabs (Blocks, Layers on left; Settings, Styles on right)
     */
    function setupSidebarTabs() {
        $('.sidebar-tab').on('click', function() {
            var panel = $(this).data('panel');
            var $sidebar = $(this).closest('.editor-sidebar');
            
            // Update tab active state within this sidebar only
            $sidebar.find('.sidebar-tab').removeClass('active');
            $(this).addClass('active');
            
            // Show/hide panels within this sidebar only
            $sidebar.find('.sidebar-panel').hide();
            $('#' + panel + '-panel').show();
        });
    }

    /**
     * Open WordPress Media Library
     */
    function openMediaLibrary(props) {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('WordPress Media Library not available. Please reload the page.');
            return;
        }

        // Diagnostic logging
        console.log('=== Media Library Diagnostics ===');
        console.log('  wp.media:', typeof wp.media);
        console.log('  wp.media.view:', wp.media && typeof wp.media.view);
        console.log('  wp.media.view.MediaFrame:', wp.media && wp.media.view && typeof wp.media.view.MediaFrame);
        console.log('  wp.media.view.MediaFrame.Select:', wp.media && wp.media.view && wp.media.view.MediaFrame && typeof wp.media.view.MediaFrame.Select);
        console.log('  wp.Backbone:', typeof wp.Backbone);
        console.log('  Backbone:', typeof Backbone);
        console.log('  Backbone.View:', typeof Backbone !== 'undefined' && typeof Backbone.View);
        console.log('  _:', typeof _);
        console.log('  wp.template:', typeof wp.template);
        console.log('  tmpl-media-frame:', !!document.getElementById('tmpl-media-frame'));
        console.log('  tmpl-attachment:', !!document.getElementById('tmpl-attachment'));
        console.log('  tmpl-attachments-browser:', !!document.getElementById('tmpl-attachments-browser'));
        console.log('================================');

        // Check for critical missing dependencies
        if (!wp.media.view || !wp.media.view.MediaFrame) {
            console.error('wp.media.view.MediaFrame is missing - media-views script may not have loaded');
            alert('Media Library views not loaded. The media-views script may be missing.');
            return;
        }

        openMediaLibrary._currentTarget = props && props.target ? props.target : null;
        openMediaLibrary._select = props && typeof props.select === 'function' ? props.select : null;

        // Always create a fresh frame to avoid stale state issues
        try {
            mediaFrame = wp.media({
                title: 'Select Image for Newsletter',
                button: { text: 'Insert Image' },
                multiple: false,
                library: { type: 'image' }
            });
        } catch (err) {
            console.error('Media Library error:', err);
            alert('Could not open Media Library: ' + err.message);
            mediaFrame = null;
            return;
        }

        mediaFrame.on('select', function() {
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            var component = openMediaLibrary._currentTarget || editor.getSelected();

            if (!editor || !component) return;

            editor.AssetManager.add({
                src: attachment.url,
                width: attachment.width,
                height: attachment.height,
                name: attachment.filename
            });

            // CRITICAL: GrapesJS image components store the URL as a model
            // *property* named src. getHtml() (Review & Test, save, send)
            // reads that property and overwrites attributes.src. Updating
            // only addAttributes() or the canvas <img> leaves the original
            // placeholder (e.g. placehold.co "Feature Image") in the
            // exported HTML. set('src') is the official API.
            // If the user clicked a wrapping <td>/<table>/<a>, locate the
            // first descendant image component and update it instead.
            var imgComponent = component;
            if (imgComponent.get('tagName') !== 'img') {
                var found = null;
                if (typeof imgComponent.find === 'function') {
                    var matches = imgComponent.find('img');
                    if (matches && matches.length) {
                        found = matches[0];
                    }
                }
                if (!found) {
                    // Manual depth-first search through child components.
                    (function walk(c) {
                        if (found) return;
                        if (c.get && c.get('tagName') === 'img') { found = c; return; }
                        var children = c.components ? c.components() : null;
                        if (children && children.each) {
                            children.each(walk);
                        }
                    })(imgComponent);
                }
                if (found) imgComponent = found;
            }

            imgComponent.set('src', attachment.url);
            var attrs = { src: attachment.url };
            if (attachment.alt) {
                attrs.alt = attachment.alt;
            }
            imgComponent.addAttributes(attrs);

            if (typeof openMediaLibrary._select === 'function') {
                try {
                    openMediaLibrary._select({
                        getSrc: function() { return attachment.url; },
                        src: attachment.url
                    }, true);
                } catch (err) {
                    console.warn('Asset select callback failed; src already set on the component.', err);
                }
            }

            // Best-effort DOM sync for instant visual feedback. The model
            // update above is what actually persists; this just avoids a
            // brief flash of the old image while the view re-renders.
            var el = imgComponent.view && imgComponent.view.el;
            if (el && el.tagName === 'IMG') {
                el.setAttribute('src', attachment.url);
                if (attachment.alt) el.setAttribute('alt', attachment.alt);
            }
        });

        mediaFrame.open();

        // Post-open diagnostic: check what rendered inside the modal
        setTimeout(function() {
            var modal = document.querySelector('.media-modal');
            if (modal) {
                var content = modal.querySelector('.media-frame-content');
                var title = modal.querySelector('.media-frame-title');
                console.log('=== Post-open Modal Check ===');
                console.log('  Modal found:', true);
                console.log('  Title element:', !!title, title ? title.innerHTML : 'N/A');
                console.log('  Content element:', !!content);
                console.log('  Content innerHTML length:', content ? content.innerHTML.length : 0);
                console.log('  Content children:', content ? content.children.length : 0);
                console.log('  Modal classes:', modal.className);
                console.log('  Frame element:', !!modal.querySelector('.media-frame'));
                console.log('  Router element:', !!modal.querySelector('.media-frame-router'));
                console.log('  Toolbar element:', !!modal.querySelector('.media-frame-toolbar'));
                console.log('  Browser element:', !!modal.querySelector('.attachments-browser'));
                console.log('=============================');
            } else {
                console.log('Post-open check: No .media-modal found in DOM');
            }
        }, 500);
    }

    /**
     * Escape HTML for display
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Workflow Navigation
     */
    function initWorkflowNavigation() {
        currentStep = parseInt($('#current_step').val()) || 1;

        // Next step buttons
        $('.next-step').on('click', function(e) {
            e.preventDefault();
            var nextStep = parseInt($(this).data('next'));
            if (validateStep(currentStep)) {
                goToStep(nextStep);
            }
        });

        // Previous step buttons
        $('.prev-step').on('click', function(e) {
            e.preventDefault();
            var prevStep = parseInt($(this).data('prev'));
            goToStep(prevStep);
        });

        // Arrow step indicators (clickable for completed steps)
        $('.arrow-step').on('click', function() {
            var step = parseInt($(this).data('step'));
            if ($(this).hasClass('completed') || step <= currentStep) {
                goToStep(step);
            }
        });
    }

    /**
     * Go to specific step
     */
    function goToStep(step) {
        // Save editor content before leaving step 2
        if (currentStep === 2 && editor) {
            // Get email-ready HTML with CSS inlined
            $('#newsletter_content_html').val(getEmailReadyHtml());
            $('#newsletter_content_json').val(JSON.stringify(editor.getProjectData()));
        }

        // Update arrow flow visual
        $('.arrow-step').each(function() {
            var stepNum = parseInt($(this).data('step'));
            $(this).removeClass('current completed pending');
            
            if (stepNum < step) {
                $(this).addClass('completed');
                // Replace number with checkmark
                var $content = $(this).find('.arrow-content');
                if ($content.find('.step-num').length) {
                    $content.find('.step-num').replaceWith('<span class="dashicons dashicons-yes-alt"></span>');
                }
            } else if (stepNum === step) {
                $(this).addClass('current');
                // Ensure it shows the number
                var $content = $(this).find('.arrow-content');
                if ($content.find('.dashicons').length) {
                    $content.find('.dashicons').replaceWith('<span class="step-num">' + stepNum + '</span>');
                }
            } else {
                $(this).addClass('pending');
                var $content = $(this).find('.arrow-content');
                if ($content.find('.dashicons').length) {
                    $content.find('.dashicons').replaceWith('<span class="step-num">' + stepNum + '</span>');
                }
            }
        });

        // Show/hide content
        $('.step-content').hide();
        $('#step-' + step + '-content').show();

        // Update current step
        currentStep = step;
        $('#current_step').val(step);

        // Step-specific initialization
        if (step === 2 && !editor && $('#gjs-editor').length) {
            initGrapesJS();
        }
        
        if (step === 3) {
            // Review shows the saved view: use hidden fields (set when leaving step 2 or from save response).
            // Do not overwrite from editor here so that after Update/Save Draft, Review shows what was just saved.
            updateReviewSummary();
            setTimeout(function() {
                updatePreview();
            }, 100);
        }
        
        if (step === 4) {
            updateFinalSummary();
        }

        // Scroll to top
        $('html, body').animate({
            scrollTop: $('.newsletter-editor-wrap').offset().top - 50
        }, 300);
    }

    /**
     * Validate step before proceeding
     */
    function validateStep(step) {
        var errors = [];

        if (step === 1) {
            var name = $('#newsletter_name').val().trim();
            var subject = $('#newsletter_subject').val().trim();
            var fromEmail = ($('#newsletter_from_email_input').val() || '').trim();
            var recipients = $('input[name="newsletter_lists[]"]:checked').length;

            if (!name) {
                errors.push('Please enter a newsletter name.');
                $('#newsletter_name').focus();
            }
            if (!subject) {
                errors.push('Please enter an email subject.');
            }
            if (!fromEmail) {
                errors.push('Please enter a sender email.');
            }
            if (recipients === 0) {
                errors.push('Please select at least one recipient list.');
            }
        }

        if (errors.length > 0) {
            alert(errors.join('\n'));
            return false;
        }

        return true;
    }

    /**
     * Display text for the chosen sender. The From control used to be a
     * <select id="newsletter_from">; it is now a hidden `email|name` field
     * plus visible name/email inputs (and an optional saved-sender picker).
     * Reading option:selected on the hidden input is always empty, which is
     * why Schedule & Send showed "Not selected".
     */
    function getFromDisplayText() {
        var name = ($('#newsletter_from_name_input').val() || '').trim();
        var email = ($('#newsletter_from_email_input').val() || '').trim();

        if (!name && !email) {
            var hidden = ($('#newsletter_from').val() || '').trim();
            if (hidden) {
                var parts = hidden.split('|');
                email = (parts[0] || '').trim();
                name = (parts[1] || '').trim();
            }
        }

        if (name && email) {
            return name + ' <' + email + '>';
        }
        return email || name || 'Not selected';
    }

    /**
     * Update review summary (Step 3)
     */
    function updateReviewSummary() {
        $('#summary-subject').text($('#newsletter_subject').val());
        $('#summary-from').text(getFromDisplayText());
        
        var selectedLists = [];
        $('input[name="newsletter_lists[]"]:checked').each(function() {
            selectedLists.push($(this).closest('label').find('strong').text());
        });
        $('#summary-recipients').text(selectedLists.join(', ') || 'None selected');
    }
    
    /**
     * Update final summary (Step 4)
     */
    function updateFinalSummary() {
        // Name
        $('#summary-name-final').text($('#newsletter_name').val() || '-');
        
        // Subject
        $('#summary-subject-final').text($('#newsletter_subject').val() || '-');
        
        // From
        $('#summary-from-final').text(getFromDisplayText());
        
        // Recipients - show lists and count
        var selectedLists = [];
        var totalCount = 0;
        $('input[name="newsletter_lists[]"]:checked').each(function() {
            var listName = $(this).closest('label').find('strong').text();
            var listCount = $(this).closest('label').find('.list-count').text();
            selectedLists.push(listName + (listCount ? ' ' + listCount : ''));
        });
        
        if (selectedLists.length > 0) {
            var recipientHtml = '<ul style="margin: 0; padding-left: 18px; list-style: disc;">';
            selectedLists.forEach(function(list) {
                recipientHtml += '<li>' + list + '</li>';
            });
            recipientHtml += '</ul>';
            $('#summary-recipients-final').html(recipientHtml);
        } else {
            $('#summary-recipients-final').html('<span style="color:#d63638;">⚠ No recipients selected</span>');
        }
        
        // Check if spam score was calculated in step 3
        var spamResult = $('#spam-score-result').html();
        if (spamResult && $('#spam-score-result').is(':visible')) {
            // Extract the score if available
            var scoreMatch = spamResult.match(/Score:\s*([\d.]+)/);
            if (scoreMatch) {
                var score = parseFloat(scoreMatch[1]);
                var scoreClass = score <= 2 ? 'spam-score-good' : (score <= 5 ? 'spam-score-warning' : 'spam-score-bad');
                $('#summary-spam-final').html('<span class="' + scoreClass + '">' + score + '/10</span>');
            } else if (spamResult.indexOf('Pass') !== -1 || spamResult.indexOf('Good') !== -1) {
                $('#summary-spam-final').html('<span class="spam-score-good">✓ Passed</span>');
            } else {
                $('#summary-spam-final').html('<em>See Review step</em>');
            }
        }
    }

    /**
     * Update preview iframe (Step 3).
     * Uses full document HTML so images and styles render.
     */
    function updatePreview() {
        var html = $('#newsletter_content_html').val();
        var frame = document.getElementById('preview-frame');
        if (frame && html) {
            var doc = frame.contentDocument || frame.contentWindow.document;
            doc.open();
            doc.write(html);
            doc.close();
        }
    }

    /**
     * Subject character count
     */
    function initSubjectCharCount() {
        $('#newsletter_subject').on('input', function() {
            var count = $(this).val().length;
            $('#subject-chars').text(count);
            if (count > 60) {
                $('#subject-chars').css('color', '#d63638');
            } else if (count > 50) {
                $('#subject-chars').css('color', '#dba617');
            } else {
                $('#subject-chars').css('color', '#646970');
            }
        }).trigger('input');
    }

    /**
     * Keep the hidden #newsletter_from input (which the AJAX save reads as
     * `email|name`) in sync with the visible name/email inputs and the
     * optional saved-sender picker. This avoids requiring users to set up
     * pre-saved sender addresses in Newsletter Settings just to send a
     * newsletter — they can type a sender directly inline.
     */
    function initFromFieldSync() {
        var $hidden = $('#newsletter_from');
        var $name   = $('#newsletter_from_name_input');
        var $email  = $('#newsletter_from_email_input');
        var $picker = $('#newsletter_from_picker');

        if (!$hidden.length || !$name.length || !$email.length) {
            return;
        }

        function sync() {
            var name  = ($name.val() || '').trim();
            var email = ($email.val() || '').trim();
            $hidden.val(email + '|' + name);
        }

        $name.on('input change', sync);
        $email.on('input change', sync);

        if ($picker.length) {
            $picker.on('change', function() {
                var val = $picker.val();
                if (!val) { return; }
                var parts = val.split('|');
                $email.val(parts[0] || '');
                $name.val(parts[1] || '');
                sync();
            });
        }

        sync();
    }

    /**
     * Sticky help bar above the editor canvas. Stays dismissed across
     * sessions per-user via localStorage, but never blocks usage.
     */
    function initEditorHelpBar() {
        var $bar = $('#editor-help-bar');
        if (!$bar.length) { return; }

        var STORAGE_KEY = 'pta_newsletter_help_dismissed';
        try {
            if (window.localStorage && localStorage.getItem(STORAGE_KEY) === '1') {
                $bar.addClass('is-dismissed');
            }
        } catch (e) { /* localStorage might be blocked; ignore */ }

        $bar.on('click', '.editor-help-dismiss', function () {
            $bar.addClass('is-dismissed');
            try {
                if (window.localStorage) {
                    localStorage.setItem(STORAGE_KEY, '1');
                }
            } catch (e) { /* ignore */ }
        });
    }

    /**
     * Recipient checkboxes
     */
    function initRecipientCheckboxes() {
        $('input[name="newsletter_lists[]"]').on('change', function() {
            updateRecipientCount();
        });
        updateRecipientCount();
    }

    /**
     * Update recipient count based on selected lists
     */
    function updateRecipientCount() {
        var selectedLists = $('input[name="newsletter_lists[]"]:checked');
        
        if (selectedLists.length === 0) {
            $('#total-recipient-count').text('0');
            return;
        }

        // Sum up counts from data-count attributes
        var count = 0;
        selectedLists.each(function() {
            var listCount = parseInt($(this).data('count')) || 0;
            count += listCount;
        });
        
        $('#total-recipient-count').text(count.toLocaleString());
    }

    /**
     * Send options toggle
     */
    function initSendOptions() {
        $('input[name="send_option"]').on('change', function() {
            var option = $(this).val();
            $('#schedule-options').toggle(option === 'schedule');
            
            if (option === 'draft') {
                $('#final-send-btn, #final-send-btn-top').hide();
                $('#save-draft-btn').show();
            } else {
                $('#final-send-btn, #final-send-btn-top').show();
                $('#save-draft-btn').hide();
            }
        });
    }

    /**
     * Page options toggle plus live title preview / token insert.
     */
    function initPageOptions() {
        $('#create_wp_page').on('change', function() {
            $('#page-settings').toggle($(this).is(':checked'));
            if ($(this).is(':checked')) {
                updatePageTitlePreview();
            }
        });

        $(document).on('click', '.insert-page-title-token', function() {
            var token = $(this).data('token');
            var $input = $('#page_title');
            if (!$input.length) {
                return;
            }
            var el = $input[0];
            var start = el.selectionStart || $input.val().length;
            var end = el.selectionEnd || start;
            var value = $input.val() || '';
            $input.val(value.slice(0, start) + token + value.slice(end));
            $input.trigger('input');
            $input.focus();
        });

        $('#page_title, #newsletter_subject, #newsletter_name').on('input', updatePageTitlePreview);
        updatePageTitlePreview();
    }

    function resolvePageTitlePreview(template) {
        var now = new Date();
        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var month = months[now.getMonth()];
        var year = String(now.getFullYear());
        var date = month + ' ' + now.getDate() + ', ' + year;
        var subject = ($('#newsletter_subject').val() || '').trim() || 'Subject';
        var name = ($('#newsletter_name').val() || '').trim() || 'Campaign name';
        return String(template || '{subject}')
            .replace(/\{subject\}/gi, subject)
            .replace(/\{name\}/gi, name)
            .replace(/\{month_year\}/gi, month + ' ' + year)
            .replace(/\{month\}/gi, month)
            .replace(/\{year\}/gi, year)
            .replace(/\{date\}/gi, date)
            .replace(/\s+/g, ' ')
            .trim();
    }

    function updatePageTitlePreview() {
        var $preview = $('#page-title-preview');
        if (!$preview.length) {
            return;
        }
        var tpl = ($('#page_title').val() || '').trim() || '{subject}';
        $preview.text(resolvePageTitlePreview(tpl) || 'Newsletter');
    }

    function appendArchivePageFields(formData) {
        formData.create_wp_page = $('#create_wp_page').is(':checked') ? 1 : 0;
        formData.page_category = $('#page_category').val() || 'newsletter';
        formData.page_title = $('#page_title').val() || '{subject}';
        formData.page_parent = $('#page_parent').val() || '0';
        return formData;
    }

    /**
     * Preview device toggle (Step 3)
     */
    $(document).on('click', '.preview-device', function() {
        var device = $(this).data('device');
        $('.preview-device').removeClass('active');
        $(this).addClass('active');
        
        if (device === 'mobile') {
            $('#preview-frame').addClass('mobile');
        } else {
            $('#preview-frame').removeClass('mobile');
        }
    });

    /**
     * Spam score check - supports local and external (SpamAssassin) checks
     */
    $(document).on('click', '#check-spam-score', function() {
        var btn = $(this);
        var useExternal = $('#use-spamassassin').is(':checked');
        
        btn.prop('disabled', true).text(useExternal ? 'Running SpamAssassin...' : 'Checking...');

        $.post(newsletterEditorConfig.ajaxUrl, {
            action: 'azure_newsletter_spam_check',
            nonce: newsletterEditorConfig.nonce,
            html: $('#newsletter_content_html').val(),
            subject: $('#newsletter_subject').val(),
            from_email: $('select[name="from_address"]').val() || 'test@example.com',
            use_external: useExternal ? 'true' : 'false'
        }, function(response) {
            btn.prop('disabled', false).text('Check Spam Score');
            var result = $('#spam-score-result').show();
            
            if (response.success) {
                var score = response.data.score;
                var scoreClass = score <= 3 ? 'good' : (score <= 5 ? 'warning' : 'bad');
                
                var html = '<div class="spam-score">' +
                    '<span class="spam-score-value ' + scoreClass + '">' + score.toFixed(1) + '/10</span>' +
                    '<span>' + response.data.message + '</span>' +
                    '</div>';
                
                // Show which checks were performed
                var checks = response.data.checks_performed || {};
                html += '<p class="spam-checks-info" style="font-size:12px;color:#666;margin:5px 0;">';
                html += '<strong>Checks:</strong> Local';
                if (checks.spamassassin) {
                    html += ' + SpamAssassin';
                }
                html += '</p>';
                
                // Show issues grouped by type
                if (response.data.issues && response.data.issues.length) {
                    html += '<div class="spam-issues">';
                    
                    var issuesByType = {};
                    response.data.issues.forEach(function(issue) {
                        var type = issue.type || 'other';
                        if (!issuesByType[type]) issuesByType[type] = [];
                        issuesByType[type].push(issue);
                    });
                    
                    var typeLabels = {
                        'subject': '📝 Subject Line',
                        'content': '📄 Content',
                        'compliance': '⚖️ Compliance',
                        'spamassassin': '🔍 SpamAssassin',
                        'other': 'Other'
                    };
                    
                    for (var type in issuesByType) {
                        html += '<div class="issue-group">';
                        html += '<strong>' + (typeLabels[type] || type) + '</strong>';
                        html += '<ul>';
                        issuesByType[type].forEach(function(issue) {
                            var issueText = typeof issue === 'string' ? issue : issue.message;
                            var issueScore = issue.score ? ' <span style="color:#d63638;">(+' + issue.score + ')</span>' : '';
                            html += '<li>' + issueText + issueScore + '</li>';
                        });
                        html += '</ul></div>';
                    }
                    html += '</div>';
                }
                
                // Show SpamAssassin score separately if available
                if (response.data.external_result && response.data.external_result.success) {
                    html += '<p class="sa-score" style="margin-top:10px;padding:8px;background:#f0f6fc;border-radius:4px;">';
                    html += '<strong>SpamAssassin Score:</strong> ' + response.data.external_result.score.toFixed(1);
                    html += ' <span style="color:#666;">(5+ is typically spam)</span>';
                    html += '</p>';
                }
                
                result.html(html);
            } else {
                result.html('<p class="error" style="color:#d63638;">' + (response.data || 'Error checking spam score') + '</p>');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Check Spam Score');
            $('#spam-score-result').show().html('<p class="error" style="color:#d63638;">Request failed. Please try again.</p>');
        });
    });

    /**
     * Accessibility check
     */
    $(document).on('click', '#check-accessibility', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Checking...');

        $.post(newsletterEditorConfig.ajaxUrl, {
            action: 'azure_newsletter_accessibility_check',
            nonce: newsletterEditorConfig.nonce,
            html: $('#newsletter_content_html').val()
        }, function(response) {
            btn.prop('disabled', false).text('Check Accessibility');
            var result = $('#accessibility-result').show();
            
            if (response.success && response.data.checks) {
                var html = '';
                response.data.checks.forEach(function(check) {
                    var icon = check.pass ? 'yes' : 'no';
                    var status = check.pass ? 'pass' : 'fail';
                    html += '<div class="accessibility-item ' + status + '">' +
                            '<span class="dashicons dashicons-' + icon + '"></span>' +
                            '<span>' + check.message + '</span>' +
                            '</div>';
                });
                result.html(html);
            } else {
                result.html('<p class="error" style="color:#d63638;">Error checking accessibility</p>');
            }
        });
    });

    /**
     * Send test email
     */
    $(document).on('click', '#send-test-email', function() {
        var btn = $(this);
        var email = $('#test_email').val();
        
        if (!email) {
            alert('Please enter an email address.');
            return;
        }

        btn.prop('disabled', true).text('Sending...');
        
        // Sync latest content from editor before sending
        var htmlContent = $('#newsletter_content_html').val();
        if (editor) {
            htmlContent = getEmailReadyHtml();
            $('#newsletter_content_html').val(htmlContent);
        }

        $.post(newsletterEditorConfig.ajaxUrl, {
            action: 'azure_newsletter_send_test',
            nonce: newsletterEditorConfig.nonce,
            email: email,
            html: htmlContent,
            subject: $('#newsletter_subject').val(),
            from: $('#newsletter_from').val(),
            newsletter_id: $('#newsletter_id').val() || 0
        }, function(response) {
            btn.prop('disabled', false).text('Send Test');
            var result = $('#test-send-result').show();
            
            if (response.success) {
                result.html('<p style="color:#00a32a;">✓ Test email sent to ' + email + '</p>');
            } else {
                result.html('<p style="color:#d63638;">✗ ' + (response.data || 'Failed to send test email') + '</p>');
            }
        });
    });

    /**
     * Insert personalization tag into subject
     */
    $(document).on('click', '.insert-personalization', function() {
        var tag = $(this).data('tag');
        var input = $('#newsletter_subject')[0];
        var val = $(input).val();
        var start = input.selectionStart;
        var end = input.selectionEnd;
        
        $(input).val(val.substring(0, start) + tag + val.substring(end));
        $(input).trigger('input');
        
        // Set cursor position after tag
        input.selectionStart = input.selectionEnd = start + tag.length;
        input.focus();
    });

    function syncEditorToHiddenFields() {
        if (!editor) {
            return;
        }
        $('#newsletter_content_html').val(getEmailReadyHtml());
        $('#newsletter_content_json').val(JSON.stringify(editor.getProjectData()));
    }

    function openSaveTemplateModal() {
        syncEditorToHiddenFields();
        var cfg = typeof newsletterEditorConfig !== 'undefined' ? newsletterEditorConfig : {};
        if (!$('#template_save_name').val()) {
            $('#template_save_name').val(cfg.templateName || $('#newsletter_name').val() || '');
        }
        $('#save-template-modal').css('display', 'flex').hide().fadeIn(150);
        $('#template_save_name').trigger('focus');
    }

    $(document).on('click', '#save-as-template-top, #btn-save-as-template', function(e) {
        e.preventDefault();
        openSaveTemplateModal();
    });

    $(document).on('click', '#save-template-modal .template-modal-close, #save-template-cancel', function(e) {
        e.preventDefault();
        $('#save-template-modal').fadeOut(150);
    });

    $(document).on('click', '#save-template-modal', function(e) {
        if (e.target === this) {
            $('#save-template-modal').fadeOut(150);
        }
    });

    $(document).on('click', '#save-template-confirm', function(e) {
        e.preventDefault();
        var cfg = typeof newsletterEditorConfig !== 'undefined' ? newsletterEditorConfig : {};
        var name = $.trim($('#template_save_name').val() || '');
        if (!name) {
            alert('Please enter a template name.');
            $('#template_save_name').trigger('focus');
            return;
        }

        syncEditorToHiddenFields();

        var btn = $(this);
        btn.prop('disabled', true).text('Saving…');

        $.post(cfg.ajaxUrl || newsletterEditorConfig.ajaxUrl, {
            action: 'azure_newsletter_save_template',
            nonce: cfg.nonce,
            template_id: cfg.editTemplateId || 0,
            name: name,
            description: $('#template_save_description').val() || '',
            category: $('#template_save_category').val() || 'custom',
            content_html: $('#newsletter_content_html').val(),
            content_json: $('#newsletter_content_json').val()
        }, function(response) {
            btn.prop('disabled', false).text('Save template');
            if (response && response.success) {
                $('#save-template-modal').fadeOut(150);
                $('#save-status').text(response.data && response.data.message ? response.data.message : 'Template saved.');
                if (response.data && response.data.template_id) {
                    newsletterEditorConfig.editTemplateId = response.data.template_id;
                    newsletterEditorConfig.templateName = name;
                    if (response.data.edit_url && window.history.replaceState) {
                        window.history.replaceState({}, '', response.data.edit_url);
                    }
                }
            } else {
                alert((response && response.data) || 'Could not save the template.');
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save template');
            alert('Could not save the template.');
        });
    });

    /**
     * Update Design - syncs editor content and saves draft
     */
    $(document).on('click', '#btn-update-design', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalHtml = btn.html();
        
        btn.prop('disabled', true);
        btn.html('<span class="dashicons dashicons-update spin"></span> Updating...');
        
        if (editor) {
            var inlinedHtml = getEmailReadyHtml();
            var json = JSON.stringify(editor.getProjectData());
            $('#newsletter_content_html').val(inlinedHtml);
            $('#newsletter_content_json').val(json);
        }
        
        // Trigger save draft via AJAX
        var selectedLists = [];
        $('input[name="newsletter_lists[]"]:checked').each(function() {
            selectedLists.push($(this).val());
        });
        
        var formData = {
            action: 'azure_newsletter_save',
            nonce: newsletterEditorConfig.nonce,
            newsletter_id: $('#newsletter_id').val(),
            newsletter_name: $('#newsletter_name').val(),
            newsletter_subject: $('#newsletter_subject').val(),
            newsletter_from: $('#newsletter_from').val(),
            newsletter_content_html: $('#newsletter_content_html').val(),
            newsletter_content_json: $('#newsletter_content_json').val(),
            newsletter_lists: JSON.stringify(selectedLists),
            send_option: 'draft'
        };
        
        $.post(newsletterEditorConfig.ajaxUrl, formData, function(response) {
            btn.prop('disabled', false);
            
            if (response.success) {
                if (response.data.newsletter_id) {
                    $('#newsletter_id').val(response.data.newsletter_id);
                    var newUrl = newsletterEditorConfig.ajaxUrl.replace('admin-ajax.php', 
                        'admin.php?page=azure-plugin-newsletter&action=new&id=' + response.data.newsletter_id);
                    if (window.history.replaceState) {
                        window.history.replaceState({}, '', newUrl);
                    }
                }
                
                btn.html('<span class="dashicons dashicons-yes-alt"></span> Updated!');
                $('#save-status').html('<span class="saved">✓ Design updated</span>');
                if (response.data.content_html !== undefined) {
                    $('#newsletter_content_html').val(response.data.content_html);
                }
                if (response.data.content_json !== undefined) {
                    $('#newsletter_content_json').val(response.data.content_json);
                }
                setTimeout(function() {
                    btn.html(originalHtml);
                    $('#save-status').html('');
                }, 2500);
            } else {
                btn.html(originalHtml);
                alert('Error saving: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.html(originalHtml);
            alert('Network error. Please try again.');
        });
    });

    /**
     * Save Draft functionality - accessible from all steps
     */
    $(document).on('click', '#save-draft-top, #save-draft-btn', function(e) {
        e.preventDefault();
        saveDraft($(this));
    });
    
    /**
     * Send Newsletter functionality - handles both top and bottom send buttons
     */
    $(document).on('click', '#final-send-btn, #final-send-btn-top', function(e) {
        e.preventDefault();
        sendNewsletter($(this));
    });
    
    function sendNewsletter(btn) {
        var sendOption = $('input[name="send_option"]:checked').val();
        
        // Validate
        if (sendOption === 'schedule') {
            var scheduleDate = $('#schedule_date').val();
            if (!scheduleDate) {
                alert('Please select a schedule date.');
                return;
            }
        }
        
        // Get selected lists
        var selectedLists = [];
        $('input[name="newsletter_lists[]"]:checked').each(function() {
            selectedLists.push($(this).val());
        });
        
        if (selectedLists.length === 0) {
            alert('Please select at least one recipient list.');
            return;
        }
        
        // Confirm
        var confirmMsg = sendOption === 'now' 
            ? 'Are you sure you want to send this newsletter now?' 
            : 'Are you sure you want to schedule this newsletter?';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        btn.prop('disabled', true);
        var originalHtml = btn.html();
        btn.html('<span class="dashicons dashicons-update-alt spin"></span> ' + (sendOption === 'now' ? 'Sending...' : 'Scheduling...'));
        
        // Sync GrapesJS content to hidden fields if editor exists
        if (editor) {
            var inlinedHtml = getEmailReadyHtml();
            var json = JSON.stringify(editor.getProjectData());
            $('#newsletter_content_html').val(inlinedHtml);
            $('#newsletter_content_json').val(json);
        }
        
        // Collect form data - use JSON.stringify for array to ensure proper transmission
        var formData = {
            action: 'azure_newsletter_save',
            nonce: newsletterEditorConfig.nonce,
            newsletter_id: $('#newsletter_id').val(),
            newsletter_name: $('#newsletter_name').val(),
            newsletter_subject: $('#newsletter_subject').val(),
            newsletter_from: $('#newsletter_from').val(),
            newsletter_content_html: $('#newsletter_content_html').val(),
            newsletter_content_json: $('#newsletter_content_json').val(),
            send_option: sendOption,
            newsletter_lists: JSON.stringify(selectedLists),
            schedule_date: $('#schedule_date').val(),
            schedule_time: $('#schedule_time').val()
        };
        appendArchivePageFields(formData);
        
        // Debug logging
        console.log('[Newsletter] Sending AJAX request:', {
            action: formData.action,
            send_option: formData.send_option,
            newsletter_lists: formData.newsletter_lists,
            newsletter_id: formData.newsletter_id
        });
        
        $.post(newsletterEditorConfig.ajaxUrl, formData, function(response) {
            console.log('[Newsletter] AJAX response:', response);
            btn.prop('disabled', false);
            btn.html(originalHtml);
            
            if (response.success) {
                var data = response.data;
                var queuedCount = data.queued || 0;
                var originalCount = data.original_recipients || 0;
                var blockedCount = data.blocked || 0;
                var bouncedCount = data.bounced || 0;
                var filteredTotal = data.filtered_total || 0;
                
                var msg = sendOption === 'now' 
                    ? '✓ Newsletter queued for sending!'
                    : '✓ Newsletter scheduled successfully!';
                
                // Build detailed recipient summary
                msg += '\n\n📊 Recipient Summary:';
                msg += '\n• ' + queuedCount + ' email(s) will be sent';
                
                // Show filtering details if any were filtered
                if (filteredTotal > 0) {
                    msg += '\n\n⚠️ ' + filteredTotal + ' recipient(s) excluded:';
                    if (blockedCount > 0) {
                        msg += '\n  • ' + blockedCount + ' blocked (manually suppressed)';
                    }
                    if (bouncedCount > 0) {
                        msg += '\n  • ' + bouncedCount + ' bounced (hard bounce)';
                    }
                }
                
                // Show warning if no emails were queued
                if (queuedCount === 0 && (sendOption === 'now' || sendOption === 'schedule')) {
                    msg += '\n\n❌ No emails will be sent!';
                    
                    if (filteredTotal > 0 && originalCount > 0 && filteredTotal >= originalCount) {
                        msg += '\nAll recipients were filtered out.';
                    } else if (originalCount === 0) {
                        msg += '\nNo recipients found in selected list(s).';
                    }
                    
                    if (data.errors && data.errors.length > 0) {
                        msg += '\n\nErrors:\n• ' + data.errors.join('\n• ');
                    }
                }
                
                alert(msg);
                
                // Redirect to campaigns
                window.location.href = newsletterEditorConfig.ajaxUrl.replace('admin-ajax.php', 
                    'admin.php?page=azure-plugin-newsletter&tab=campaigns');
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            btn.prop('disabled', false);
            btn.html(originalHtml);
            alert('Network error. Please try again.');
        });
    }
    
    function saveDraft(btn) {
        var statusEl = $('#save-status');
        var originalText = btn.find('.dashicons').length ? btn.html() : btn.text();
        
        // Update button state
        btn.prop('disabled', true);
        if (btn.attr('id') === 'save-draft-top') {
            btn.html('<span class="dashicons dashicons-update-alt spin"></span> Saving...');
        } else {
            btn.text('Saving...');
        }
        statusEl.html('<span class="saving">Saving...</span>');
        
        // Sync GrapesJS content to hidden fields if editor exists
        if (editor) {
            var inlinedHtml = getEmailReadyHtml();
            var json = JSON.stringify(editor.getProjectData());
            $('#newsletter_content_html').val(inlinedHtml);
            $('#newsletter_content_json').val(json);
        }
        
        // Get selected lists
        var selectedLists = [];
        $('input[name="newsletter_lists[]"]:checked').each(function() {
            selectedLists.push($(this).val());
        });
        
        // Collect form data - use JSON.stringify for array to ensure proper transmission
        var formData = {
            action: 'azure_newsletter_save',
            nonce: newsletterEditorConfig.nonce,
            newsletter_id: $('#newsletter_id').val(),
            newsletter_name: $('#newsletter_name').val(),
            newsletter_subject: $('#newsletter_subject').val(),
            newsletter_from: $('#newsletter_from').val(),
            newsletter_content_html: $('#newsletter_content_html').val(),
            newsletter_content_json: $('#newsletter_content_json').val(),
            newsletter_lists: JSON.stringify(selectedLists),
            send_option: 'draft' // Always save as draft
        };
        appendArchivePageFields(formData);
        
        $.post(newsletterEditorConfig.ajaxUrl, formData, function(response) {
            btn.prop('disabled', false);
            
            if (response.success) {
                // Update newsletter ID if new
                if (response.data.newsletter_id) {
                    $('#newsletter_id').val(response.data.newsletter_id);
                    // Update URL without reload
                    var newUrl = newsletterEditorConfig.ajaxUrl.replace('admin-ajax.php', 
                        'admin.php?page=azure-plugin-newsletter&action=new&id=' + response.data.newsletter_id);
                    if (window.history.replaceState) {
                        window.history.replaceState({}, '', newUrl);
                    }
                }
                
                if (btn.attr('id') === 'save-draft-top') {
                    btn.html('<span class="dashicons dashicons-cloud-saved"></span> Save Draft');
                } else {
                    btn.text('Save Draft');
                }
                statusEl.html('<span class="saved">✓ Saved</span>');
                if (response.data.content_html !== undefined) {
                    $('#newsletter_content_html').val(response.data.content_html);
                }
                if (response.data.content_json !== undefined) {
                    $('#newsletter_content_json').val(response.data.content_json);
                }
                setTimeout(function() {
                    statusEl.html('');
                }, 3000);
            } else {
                if (btn.attr('id') === 'save-draft-top') {
                    btn.html('<span class="dashicons dashicons-cloud-saved"></span> Save Draft');
                } else {
                    btn.text('Save Draft');
                }
                statusEl.html('<span class="error">✗ Failed to save</span>');
                alert('Error saving: ' + (response.data || 'Unknown error'));
            }
        }).fail(function() {
            btn.prop('disabled', false);
            if (btn.attr('id') === 'save-draft-top') {
                btn.html('<span class="dashicons dashicons-cloud-saved"></span> Save Draft');
            } else {
                btn.text('Save Draft');
            }
            statusEl.html('<span class="error">✗ Failed to save</span>');
            alert('Network error. Please try again.');
        });
    }

    window.azureNewsletterEditorApi = {
        getEditor: function() { return editor; },
        getEmailReadyHtml: getEmailReadyHtml
    };

})(jQuery);
