(function () {
    'use strict';

    var HINT = (window.ptaMembershipCheckout && window.ptaMembershipCheckout.hint) ||
        'Enter your email and password first, then you can use Apple Pay or Google Pay.';

    var EXPRESS_SELECTOR = [
        '.wp-block-woocommerce-checkout-express-payment-block',
        '.wc-block-components-express-payment--checkout',
        '.wc-block-checkout-express-payment',
        '#wc-stripe-payment-request-wrapper',
        '#wcpay-payment-request-wrapper',
        '.wcpay-express-checkout',
        '.wc-stripe-payment-request-wrapper'
    ].join(',');

    var CONTACT_SELECTOR = [
        '.wp-block-woocommerce-checkout-contact-information-block',
        '.wc-block-checkout__contact-fields',
        '.woocommerce-account-fields',
        '#customer_details'
    ].join(',');

    function emailOk(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    }

    function readEmail() {
        var selectors = ['#email', '#billing_email', 'input[type="email"]'];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el && el.value) {
                return el.value;
            }
        }
        try {
            if (window.wp && wp.data && wp.data.select) {
                var cart = wp.data.select('wc/store/cart');
                if (cart && cart.getCustomerData) {
                    var data = cart.getCustomerData();
                    if (data && data.billingAddress && data.billingAddress.email) {
                        return data.billingAddress.email;
                    }
                }
            }
        } catch (e) {}
        return '';
    }

    function readPassword() {
        var selectors = [
            '#account_password',
            '#account-password',
            '#reg_password',
            'input[type="password"]'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el && el.value) {
                return el.value;
            }
        }
        return '';
    }

    function fieldsReady() {
        return emailOk(readEmail()) && String(readPassword()).length > 0;
    }

    function expressRoots() {
        return Array.prototype.slice.call(document.querySelectorAll(EXPRESS_SELECTOR));
    }

    function contactAnchor() {
        return document.querySelector(CONTACT_SELECTOR);
    }

    function moveExpressAfterContact() {
        var contact = contactAnchor();
        if (!contact) {
            return;
        }
        expressRoots().forEach(function (root) {
            if (!root || root === contact || contact.contains(root)) {
                return;
            }
            if (contact.compareDocumentPosition(root) & Node.DOCUMENT_POSITION_FOLLOWING) {
                return;
            }
            contact.after(root);
        });
    }

    function ensureHint(root) {
        var hint = root.querySelector('.pta-express-account-hint');
        if (!hint) {
            hint = document.createElement('p');
            hint.className = 'pta-express-account-hint';
            hint.textContent = HINT;
            root.insertBefore(hint, root.firstChild);
        }
        return hint;
    }

    function ensureCover(root) {
        var cover = root.querySelector('.pta-express-lock-cover');
        if (!cover) {
            cover = document.createElement('div');
            cover.className = 'pta-express-lock-cover';
            root.appendChild(cover);
        }
        return cover;
    }

    var syncing = false;

    function markCreateAccount() {
        try {
            if (window.wp && wp.data && wp.data.dispatch) {
                var checkout = wp.data.dispatch('wc/store/checkout');
                if (checkout && checkout.__internalSetShouldCreateAccount) {
                    checkout.__internalSetShouldCreateAccount(true);
                }
            }
        } catch (e) {}
    }

    function sync() {
        if (syncing) {
            return;
        }
        syncing = true;
        try {
            var ready = fieldsReady();
            moveExpressAfterContact();
            expressRoots().forEach(function (root) {
                root.classList.toggle('pta-express-locked', !ready);
                ensureHint(root).hidden = ready;
                ensureCover(root).hidden = ready;
            });
            if (ready) {
                markCreateAccount();
            }
        } finally {
            syncing = false;
        }
    }

    function bind() {
        document.addEventListener('input', function (e) {
            var t = e.target;
            if (!t || !t.matches) {
                return;
            }
            if (t.matches('input[type="email"], input[type="password"], #email, #billing_email')) {
                sync();
            }
        });
        document.addEventListener('click', function (e) {
            var locked = e.target.closest && e.target.closest('.pta-express-locked');
            if (!locked) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var contact = contactAnchor();
            if (contact && contact.scrollIntoView) {
                contact.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, true);
        var observer = new MutationObserver(function () {
            sync();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
