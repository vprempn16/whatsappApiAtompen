import os
import tempfile
from flask import Flask, request, jsonify
import whisper

app = Flask(__name__)

# Load base model. It will download on first run.
model = whisper.load_model('base')

@app.route('/transcribe', methods=['POST'])
def transcribe():
    if 'audio' not in request.files:
        return jsonify({"error": "No audio file provided"}), 400
        
    audio_file = request.files['audio']
    if audio_file.filename == '':
        return jsonify({"error": "Empty filename"}), 400

    _, ext = os.path.splitext(audio_file.filename)
    fd, temp_path = tempfile.mkstemp(suffix=ext)
    
    try:
        with os.fdopen(fd, 'wb') as f:
            audio_file.save(f)
            
        result = model.transcribe(temp_path)
        return jsonify({"text": result.get("text", "").strip()})
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({"error": str(e), "trace": traceback.format_exc()}), 500
    finally:
        if os.path.exists(temp_path):
            os.remove(temp_path)

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=5000)
