(function () {
    if (window.dhtCampaignStripBound) return;
    window.dhtCampaignStripBound = true;

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-dht-campaign-scroll]');
        if (!button) return;

        var block = button.closest('.dht-campaign-block');
        if (!block) return;

        var viewport = block.querySelector('.dht-campaign-viewport');
        if (!viewport) return;

        var direction = button.getAttribute('data-dht-campaign-scroll') === 'prev' ? -1 : 1;
        viewport.scrollBy({
            left: direction * Math.max(240, viewport.clientWidth * 0.88),
            behavior: 'smooth'
        });
    });
}());
