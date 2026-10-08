-- Transcrição de áudios sob demanda (Whisper API): cache 1:1 em messages.
ALTER TABLE messages ADD COLUMN transcription TEXT NULL, ADD COLUMN transcribed_at DATETIME NULL;
