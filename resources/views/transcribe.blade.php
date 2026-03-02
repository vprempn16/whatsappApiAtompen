<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Speech to Text</title>
</head>

<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; line-height: 1.6;">
    <h2>Upload Audio for Transcription</h2>

    <form id="transcription-form" enctype="multipart/form-data">
        @csrf
        <div style="margin-bottom: 20px;">
            <input type="file" name="audio" required>
        </div>
        <button type="submit" style="padding: 10px 20px; font-size: 16px;">Transcribe Audio</button>
    </form>

    <div style="margin-top: 30px;">
        <h3>Result:</h3>
        <pre id="transcription-result"
            style="background: #f4f4f4; padding: 15px; border: 1px solid #ddd; min-height: 80px; white-space: pre-wrap; word-wrap: break-word;"></pre>
    </div>

    <script>
        document.getElementById('transcription-form').addEventListener('submit', async function (e) {
            e.preventDefault();

            const resultBox = document.getElementById('transcription-result');
            const submitBtn = this.querySelector('button[type="submit"]');

            resultBox.textContent = 'Transcribing... this may take a few moments. Please wait.';
            submitBtn.disabled = true;

            const formData = new FormData(this);

            try {
                const response = await fetch('/transcribe-audio', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (response.ok && data.text !== undefined) {
                    resultBox.textContent = data.text;
                } else {
                    resultBox.textContent = 'Error: ' + (data.error || JSON.stringify(data));
                }
            } catch (error) {
                resultBox.textContent = 'Request failed: ' + error.message;
            } finally {
                submitBtn.disabled = false;
            }
        });
    </script>
</body>

</html>