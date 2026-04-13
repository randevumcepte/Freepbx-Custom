import subprocess
import os

recordedFilePath = "/var/spool/asterisk/monitor/temp_input_c03b0e9b-2322-4999-8a2e-51c12937fd15.wav"

command = ["/usr/bin/node", "/var/lib/asterisk/agi-bin/transcribe2.js", recordedFilePath]

result = subprocess.run(command, capture_output=True, text=True)
print("Node stdout:", result.stdout)
print("Node stderr:", result.stderr)
