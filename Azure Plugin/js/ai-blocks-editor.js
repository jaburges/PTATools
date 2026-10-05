(function (wp) {
    if (!wp || !wp.blocks || !wp.element) {
        return;
    }
    var el = wp.element.createElement;
    var ServerSideRender = wp.serverSideRender;
    var __ = wp.i18n.__;

    function placeholder(text) {
        return el('div', { className: 'pta-ai-placeholder', style: { padding: '12px 16px', border: '1px dashed #c3c4c7', borderRadius: '8px', color: '#50575e', fontSize: '13px' } }, text);
    }

    wp.blocks.registerBlockType('pta-tools/ai-summary', {
        title: __('Key points (AI)', 'azure-plugin'),
        icon: 'lightbulb',
        category: 'widgets',
        usesContext: ['postId'],
        supports: { html: false },
        edit: function (props) {
            if (!props.context || !props.context.postId) {
                return placeholder(__('Key points for the story appear here.', 'azure-plugin'));
            }
            return el(ServerSideRender, {
                block: 'pta-tools/ai-summary',
                urlQueryArgs: { post_id: props.context.postId },
                EmptyResponsePlaceholder: function () {
                    return placeholder(__('Key points (AI): none for this post yet.', 'azure-plugin'));
                }
            });
        },
        save: function () { return null; }
    });

    wp.blocks.registerBlockType('pta-tools/ai-language-switcher', {
        title: __('Language switcher (AI)', 'azure-plugin'),
        icon: 'translation',
        category: 'widgets',
        supports: { html: false },
        edit: function () {
            return placeholder(__('Language switcher: shown on posts when translation is on.', 'azure-plugin'));
        },
        save: function () { return null; }
    });
})(window.wp);
