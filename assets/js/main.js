/* ============================================================
   ELEGANCE SALON - small site-wide scripts
   ============================================================ */

// 1. Auto-hide success/info alerts after 5 seconds
setTimeout(function () {
    document.querySelectorAll('.alert-success, .alert-info').forEach(function (el) {
        if (window.bootstrap && bootstrap.Alert) {
            bootstrap.Alert.getOrCreateInstance(el).close();
        }
    });
}, 5000);

// 2. Confirmation prompts.
//    Add  data-confirm="Delete this client?"  to a form or a link.
document.addEventListener('submit', function (e) {
    var el = e.target;
    if (el.matches('[data-confirm]') && !window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
    }
}, true);

document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
    }
});
