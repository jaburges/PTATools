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
        
        // Get HTML and CSS separately from GrapesJS
        var html = editor.getHtml();
        var css = editor.getCss();
        
        // Aggressively clean up the HTML - remove any CSS text that leaked in
        html = cleanHtmlContent(html);

        // FAQ blocks are authored with <details open> so the answer is
        // visible/editable in the canvas. Strip the `open` attribute on
        // export so the email arrives collapsed-by-default in clients
        // that honour the disclosure triangle. Operates on serialized
        // HTML rather than the live model, so editor state is not
        // mutated.
        html = stripFaqOpenAttr(html);
        
        // Build proper email HTML structure
        var emailHtml = '<!DOCTYPE html>\n';
        emailHtml += '<html>\n<head>\n';
        emailHtml += '<meta charset="UTF-8">\n';
        emailHtml += '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n';
        emailHtml += '<meta http-equiv="X-UA-Compatible" content="IE=edge">\n';
        emailHtml += '<title>Newsletter</title>\n';
        
        // Add CSS in head (better than body, some clients support it)
        if (css) {
            emailHtml += '<style type="text/css">\n';
            emailHtml += '/* Email Reset */\n';
            emailHtml += 'body { margin: 0 !important; padding: 0 !important; }\n';
            emailHtml += 'table { border-collapse: collapse !important; }\n';
            emailHtml += 'img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }\n';
            emailHtml += css + '\n';
            emailHtml += '</style>\n';
        }
        
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
                                'font-family',
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

            // Add custom email blocks
            addEmailBlocks();
            
            // Register custom component types with traits
            registerComponentTypes();

            // grapesjs-preset-newsletter adds its own device-switcher
            // panel ('devices-c') on the left side of the toolbar that
            // duplicates the device buttons we render in our top
            // toolbar (.device-buttons). Remove it so users don't see
            // two sets of identical Desktop/Tablet/Mobile controls.
            try {
                if (editor.Panels && editor.Panels.removePanel) {
                    editor.Panels.removePanel('devices-c');
                }
            } catch (e) { /* not critical */ }

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
     * Add custom email blocks with modern visual previews
     */
    function addEmailBlocks() {
        if (!editor) return;

        var bm = editor.BlockManager;

        // The grapesjs-preset-newsletter plugin pre-registers its own
        // 'button' block whose content is `<a class="button">Button</a>`
        // — i.e. an unstyled link that renders as plain blue underlined
        // text in our canvas because we don't ship that .button class.
        // Remove it before our richer table-based replacement is added,
        // otherwise users dragging the (visually identical) icon into
        // the page get the preset's bare link instead of our styled
        // button.
        if (bm.get('button')) {
            bm.remove('button');
        }

        // Clean Elementor-style SVG icons
        var c = '#6d7882'; // Icon color
        var icons = {
            // Layout
            section: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="4" y1="18" x2="44" y2="18" stroke="'+c+'" stroke-width="2"/></svg>',
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
            faq: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect x="4" y="6" width="40" height="10" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><polyline points="36,10 39,13 42,10" fill="none" stroke="'+c+'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="20" width="40" height="22" rx="2" fill="none" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="27" x2="39" y2="27" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="33" x2="35" y2="33" stroke="'+c+'" stroke-width="2"/><line x1="9" y1="39" x2="30" y2="39" stroke="'+c+'" stroke-width="2"/></svg>'
        };

        // === LAYOUT BLOCKS ===
        bm.add('section', {
            label: 'Section',
            category: 'Layout',
            media: icons.section,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px; background-color: #ffffff;">
                            <p>Section content here...</p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('columns-2', {
            label: '2 Columns',
            category: 'Layout',
            media: icons.columns2,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td width="50%" valign="top" style="padding: 10px;">
                            <p>Left column</p>
                        </td>
                        <td width="50%" valign="top" style="padding: 10px;">
                            <p>Right column</p>
                        </td>
                    </tr>
                </table>
            `
        });

        bm.add('columns-3', {
            label: '3 Columns',
            category: 'Layout',
            media: icons.columns3,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td width="33%" valign="top" style="padding: 10px;">
                            <p>Column 1</p>
                        </td>
                        <td width="34%" valign="top" style="padding: 10px;">
                            <p>Column 2</p>
                        </td>
                        <td width="33%" valign="top" style="padding: 10px;">
                            <p>Column 3</p>
                        </td>
                    </tr>
                </table>
            `
        });

        // === CONTENT BLOCKS ===
        bm.add('text-block', {
            label: 'Text',
            category: 'Content',
            media: icons.text,
            content: `
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 10px 20px; font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333333;">
                            <p>Add your text content here. You can style this text using the Styles panel.</p>
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
                        <td style="padding: 10px 20px;">
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
                            <img src="https://via.placeholder.com/600x300/e0e0e0/666666?text=Click+to+add+image" alt="Image" width="600" style="display: block; max-width: 100%; height: auto;">
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
                <table cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 15px auto;">
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
                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 20px;">
                            <hr style="border: none; border-top: 1px solid #dddddd; margin: 0;">
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
     * double-clicks editable text. We replace the default link action
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
                return el.tagName === 'TABLE' && el.querySelector('a[href]') && el.innerHTML.indexOf('Click Here') > -1;
            },
            model: {
                defaults: {
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
                            name: 'src'
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
     * Setup component selection handling for Settings panel
     */
    function setupComponentSelection() {
        if (!editor) return;
        
        // Listen for component selection
        editor.on('component:selected', function(component) {
            // Show settings placeholder or traits
            var traitsContainer = $('#traits-container');
            var placeholder = $('.settings-placeholder');
            
            if (component) {
                var traits = component.get('traits');
                if (traits && traits.length > 0) {
                    placeholder.hide();
                    traitsContainer.show();
                } else {
                    placeholder.show();
                    traitsContainer.hide();
                }
                
                // Update element indicator in Styles panel
                updateElementIndicator(component);
            } else {
                placeholder.show();
                traitsContainer.hide();
                $('#selected-element-name .element-name').text('No element selected');
            }
        });
        
        // Listen for component deselection
        editor.on('component:deselected', function() {
            $('.settings-placeholder').show();
            $('#traits-container').hide();
            $('#selected-element-name .element-name').text('No element selected');
        });
        
        // Double-click on image components opens the media library
        editor.on('component:dblclick', function(component) {
            if (!component) return;
            var el = component.view && component.view.el;
            if (!el) return;
            
            if (el.tagName === 'IMG') {
                openMediaLibrary({ target: component });
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
                                        <p>Use the Styles panel to customize colors, fonts, and spacing.</p>
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

            // CRITICAL: We must update the GrapesJS *component model* (not
            // just the DOM element). editor.getHtml() serializes from the
            // model, so a DOM-only update would be invisible at save /
            // review time and the original placeholder URL would silently
            // come back when advancing to step 3. If the user clicked a
            // wrapping <td>/<table>/<a> instead of the <img>, locate the
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

            var attrs = { src: attachment.url };
            if (attachment.alt) {
                attrs.alt = attachment.alt;
            }
            imgComponent.addAttributes(attrs);

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
            var from = $('#newsletter_from').val();
            var recipients = $('input[name="newsletter_lists[]"]:checked').length;

            if (!name) {
                errors.push('Please enter a newsletter name.');
                $('#newsletter_name').focus();
            }
            if (!subject) {
                errors.push('Please enter an email subject.');
            }
            if (!from) {
                errors.push('Please select a sender.');
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
     * Update review summary (Step 3)
     */
    function updateReviewSummary() {
        $('#summary-subject').text($('#newsletter_subject').val());
        $('#summary-from').text($('#newsletter_from option:selected').text() || 'Not selected');
        
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
        var fromText = $('#newsletter_from option:selected').text();
        $('#summary-from-final').text(fromText || 'Not selected');
        
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
     * Page options toggle
     */
    function initPageOptions() {
        $('#create_wp_page').on('change', function() {
            $('#page-settings').toggle($(this).is(':checked'));
        });
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
            from: $('#newsletter_from').val()
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

})(jQuery);
