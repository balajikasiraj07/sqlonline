window.addEventListener('load', () => {
    const ticker = document.getElementById('ticker');
    const tickerContainer = document.querySelector('.ticker-container');

    // Duplicate the ticker content for seamless scrolling
    const clone = ticker.cloneNode(true);
    tickerContainer.appendChild(clone);
});