<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Backend Inspector</title>
</head>
<body>
    <h1>Backend Inspector</h1>

    <pre id="state">Chargement...</pre>

    <script>
        async function refreshInspector() {
            const response = await fetch('/demo/inspector/state', {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            document.getElementById('state').textContent = JSON.stringify(data, null, 2);
        }

        refreshInspector();
        setInterval(refreshInspector, 2000);
    </script>
</body>
</html>
