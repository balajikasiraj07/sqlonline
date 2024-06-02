document.getElementById('convert-button').addEventListener('click', function() {
    convertQuery();
});

document.getElementById('input-area').addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        convertQuery();
    }
});

function convertQuery() {
    const inputQuery = document.getElementById('input-area').value;

    fetch('process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            'input-query': inputQuery
        })
    })
    .then(response => response.text())
    .then(data => {
        document.getElementById('output-area').value = data;
    })
    .catch(error => {
        console.error('Error:', error);
    });
}




