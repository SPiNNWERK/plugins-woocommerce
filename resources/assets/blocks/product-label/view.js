import jQuery from 'jquery';

// Register a label that was added after the wizard's initial init() (e.g. AJAX-loaded lists).
// Guarded: optional in the external script, so labels present at load still work via init().
function registerBlock(block) {
    if (typeof window.C2EcomWizard?.registerLabel !== 'function') {
        return;
    }

    const label = block.querySelector('.c2-financing-label');
    const id = label && label.getAttribute('id');

    if (id) {
        window.C2EcomWizard.registerLabel(id, parseFloat(label.getAttribute('data-c2-financing-amount')));
    }
}

function updateBlock(block, price) {
    if (typeof window.C2EcomWizard?.refreshAmount !== 'function') {
        return;
    }

    const label = block.querySelector('.c2-financing-label');
    const id = label && label.getAttribute('id');

    if (id) {
        window.C2EcomWizard.refreshAmount(id, price.toFixed(2));
    }
}

function initBlocks() {
    document.querySelectorAll('[data-c2-block]').forEach(registerBlock);

    // Keep the label in sync with the selected variation on variable products.
    jQuery(document).on('show_variation', '.single_variation_wrap', function (event, variation) {
        const price = parseFloat(variation.display_price);

        if (Number.isNaN(price)) {
            return;
        }

        document.querySelectorAll('[data-c2-block]').forEach((block) => updateBlock(block, price));
    });

    // Pick up blocks injected after page load (paginated/filtered product lists, quick-view).
    if (typeof MutationObserver === 'function') {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== Node.ELEMENT_NODE) {
                        return;
                    }

                    if (node.matches?.('[data-c2-block]')) {
                        registerBlock(node);
                    }

                    node.querySelectorAll?.('[data-c2-block]').forEach(registerBlock);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }
}

if (document.readyState !== 'loading') {
    initBlocks();
} else {
    document.addEventListener('DOMContentLoaded', initBlocks);
}
