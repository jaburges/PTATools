/* global wp */
/**
 * Editor for the PTA Products block. Every option is a click in the block
 * settings; the preview is the server render (Azure_PTA_Products_Block).
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var __ = wp.i18n.__;
    var C = wp.components;
    var BE = wp.blockEditor;
    var apiFetch = wp.apiFetch;
    var ServerSideRender = wp.serverSideRender;
    var decode = (wp.htmlEntities && wp.htmlEntities.decodeEntities) || function (s) { return s; };

    // The Store API is public and lists only products a shopper could see.
    function fetchProducts(query) {
        return apiFetch({ path: '/wc/store/v1/products?' + query }).then(function (rows) {
            return (rows || []).map(function (p) { return { id: p.id, name: decode(p.name) }; });
        });
    }

    function CategoryControl(props) {
        var state = useState(null);
        var cats = state[0];
        var setCats = state[1];
        useEffect(function () {
            apiFetch({ path: '/wc/store/v1/products/categories' }).then(function (rows) {
                setCats((rows || []).map(function (c) {
                    return { label: decode(c.name) + ' (' + c.count + ')', value: c.slug };
                }));
            }).catch(function () { setCats([]); });
        }, []);
        if (cats === null) {
            return el(C.Spinner);
        }
        return el(C.SelectControl, {
            label: __('Category', 'azure-plugin'),
            help: __('Show products from this category. Leave on None to show only the products ticked below.', 'azure-plugin'),
            value: props.value,
            options: [{ label: __('None', 'azure-plugin'), value: '' }].concat(cats),
            onChange: props.onChange
        });
    }

    function ProductPicker(props) {
        var ids = props.value || [];
        var searchState = useState('');
        var search = searchState[0];
        var setSearch = searchState[1];
        var resultsState = useState(null);
        var results = resultsState[0];
        var setResults = resultsState[1];
        var namesState = useState({});
        var names = namesState[0];
        var setNames = namesState[1];

        function remember(rows) {
            setNames(function (prev) {
                var next = Object.assign({}, prev);
                rows.forEach(function (p) { next[p.id] = p.name; });
                return next;
            });
        }

        useEffect(function () {
            var q = 'per_page=50&orderby=title&order=asc' + (search ? '&search=' + encodeURIComponent(search) : '');
            var live = true;
            var timer = setTimeout(function () {
                fetchProducts(q).then(function (rows) {
                    if (live) { setResults(rows); remember(rows); }
                }).catch(function () { if (live) { setResults([]); } });
            }, search ? 300 : 0);
            return function () { live = false; clearTimeout(timer); };
        }, [search]);

        useEffect(function () {
            var missing = ids.filter(function (id) { return !names[id]; });
            if (missing.length) {
                fetchProducts('include=' + missing.join(',') + '&per_page=' + missing.length).then(remember).catch(function () {});
            }
        }, [ids.join(',')]);

        function toggle(id, on) {
            props.onChange(on ? ids.concat([id]) : ids.filter(function (x) { return x !== id; }));
        }
        function move(index, by) {
            var next = ids.slice();
            var to = index + by;
            if (to < 0 || to >= next.length) { return; }
            next.splice(to, 0, next.splice(index, 1)[0]);
            props.onChange(next);
        }

        var selected = ids.length ? el('div', { className: 'pta-products-picked' },
            el('p', { style: { fontWeight: 600, margin: '0 0 6px' } }, __('Showing, in this order:', 'azure-plugin')),
            ids.map(function (id, i) {
                return el('div', { key: id, style: { display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' } },
                    el('span', { style: { flex: '1 1 auto' } }, names[id] || ('#' + id)),
                    el(C.Button, { icon: 'arrow-up-alt2', label: __('Move up', 'azure-plugin'), size: 'small', disabled: i === 0, onClick: function () { move(i, -1); } }),
                    el(C.Button, { icon: 'arrow-down-alt2', label: __('Move down', 'azure-plugin'), size: 'small', disabled: i === ids.length - 1, onClick: function () { move(i, 1); } }),
                    el(C.Button, { icon: 'no-alt', label: __('Remove', 'azure-plugin'), size: 'small', onClick: function () { toggle(id, false); } })
                );
            })
        ) : null;

        return el('div', null,
            el('p', { style: { fontWeight: 600, margin: '16px 0 6px' } }, __('Products', 'azure-plugin')),
            selected,
            el(C.SearchControl, { value: search, onChange: setSearch, placeholder: __('Search products', 'azure-plugin') }),
            results === null ? el(C.Spinner) : el('div', { style: { maxHeight: '240px', overflowY: 'auto' } },
                results.length ? results.map(function (p) {
                    return el(C.CheckboxControl, {
                        key: p.id,
                        label: p.name,
                        checked: ids.indexOf(p.id) !== -1,
                        onChange: function (on) { toggle(p.id, on); }
                    });
                }) : el('p', null, __('No products found.', 'azure-plugin'))
            )
        );
    }

    wp.blocks.registerBlockType('pta/products', {
        apiVersion: 2,
        title: __('PTA Products', 'azure-plugin'),
        icon: 'cart',
        category: 'widgets',
        edit: function (props) {
            var a = props.attributes;
            var set = props.setAttributes;
            return el(Fragment, null,
                el(BE.InspectorControls, null,
                    el(C.PanelBody, { title: __('Products', 'azure-plugin'), initialOpen: true },
                        el(CategoryControl, { value: a.category, onChange: function (v) { set({ category: v }); } }),
                        a.category ? el(C.RangeControl, {
                            label: __('How many from the category', 'azure-plugin'),
                            value: a.limit, min: 1, max: 24,
                            onChange: function (v) { set({ limit: v || 1 }); }
                        }) : null,
                        el(ProductPicker, { value: a.productIds, onChange: function (v) { set({ productIds: v }); } })
                    ),
                    el(C.PanelBody, { title: __('Display', 'azure-plugin'), initialOpen: true },
                        el(C.ToggleControl, {
                            label: __('Show input fields', 'azure-plugin'),
                            help: a.showFields
                                ? __('On: people fill in the product\'s fields (child, grade, teacher…) and add to cart from the card.', 'azure-plugin')
                                : __('Off: the button opens the product page, where the fields are filled in. Nothing is added from the card.', 'azure-plugin'),
                            checked: !!a.showFields,
                            onChange: function (v) { set({ showFields: v }); }
                        }),
                        el(C.RangeControl, {
                            label: __('Columns', 'azure-plugin'),
                            help: __('Use 1 in a sidebar.', 'azure-plugin'),
                            value: a.columns, min: 1, max: 4,
                            onChange: function (v) { set({ columns: v || 1 }); }
                        }),
                        el(C.ToggleControl, { label: __('Show image', 'azure-plugin'), checked: !!a.showImage, onChange: function (v) { set({ showImage: v }); } }),
                        el(C.ToggleControl, { label: __('Show price', 'azure-plugin'), checked: !!a.showPrice, onChange: function (v) { set({ showPrice: v }); } }),
                        el(C.TextControl, {
                            label: __('Button text', 'azure-plugin'),
                            help: __('Leave blank for "Select options" or "View product".', 'azure-plugin'),
                            value: a.buttonText,
                            onChange: function (v) { set({ buttonText: v }); }
                        })
                    )
                ),
                el('div', BE.useBlockProps(),
                    el(ServerSideRender, { block: 'pta/products', attributes: a })
                )
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp);
