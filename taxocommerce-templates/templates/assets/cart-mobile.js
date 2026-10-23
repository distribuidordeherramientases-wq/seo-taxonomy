(function () {
    document.addEventListener('change', function (event) {
        if (!event.target.matches('.dht-cart-form input.qty')) {
            return;
        }
        var form = event.target.closest('.dht-cart-form');
        var button = form ? form.querySelector('button[name="update_cart"]') : null;
        if (button) {
            button.disabled = false;
            button.removeAttribute('disabled');
        }
    });
})();
