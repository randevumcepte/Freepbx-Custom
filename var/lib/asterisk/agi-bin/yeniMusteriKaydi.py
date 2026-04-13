#!/usr/bin/env python3
import sys
import os
import subprocess
import json
import uuid

# Minimal AGI sınıfı
class AGI:
	def __init__(self):
		self.vars = {}
		self.env = {}
		self.read_agi_env()

	def read_agi_env(self):
		while True:
			line = sys.stdin.readline().strip()
			if line == '':
				break
			if ':' in line:
				key, value = line.split(':', 1)
				self.env[key.strip()] = value.strip()

	def answer(self):
		print("ANSWER")
		sys.stdout.flush()

	def hangup(self):
		print("HANGUP")
		sys.stdout.flush()

	def verbose(self, msg):
		print(f'VERBOSE "{msg}" 1')
		sys.stdout.flush()

	def record_file(self, file_path, format="wav", escape_digits="", max_seconds=30, beep=False, silence=2):
		# AGI RECORD FILE komutunu elle gönderiyoruz
		beep_flag = 1 if beep else 0
		cmd = f'RECORD FILE {file_path} {format} "{escape_digits}" {max_seconds} {beep_flag} {silence}'
		print(cmd)
		sys.stdout.flush()
		# Asterisk'ten yanıtı oku
		sys.stdin.readline()

	def set_variable(self, name, value):
		print(f'SET VARIABLE {name} "{value}"')
		sys.stdout.flush()

# AGI başlat
agi = AGI()

try:
	agi.answer()

	# Benzersiz kayıt dosyası
	record_id = str(uuid.uuid4())
	recordedFilePath = f"/var/spool/asterisk/monitor/temp_input_{record_id}"

	agi.verbose(f"Recording to {recordedFilePath}")

	# 30 saniye kayıt, bip kapalı, 2 saniye sessizlik ile durdur
	agi.record_file(recordedFilePath, "wav", "", 30, beep=False, silence=2)

	# Node.js transcribe çağrısı
	command = ["/usr/bin/node", "/var/lib/asterisk/agi-bin/transcribe2.js", recordedFilePath]

	try:
		output = subprocess.check_output(command, stderr=subprocess.STDOUT).decode()
	except subprocess.CalledProcessError as e:
		output = e.output.decode()

	agi.verbose(f"Node output: {output}")

	try:
		result = json.loads(output)
	except json.JSONDecodeError as e:
		agi.verbose(f"JSON decode error: {e}")
		result = {"success": False, "error": str(e)}

	if result.get("success"):
		agi.verbose(f"Transcription: {result.get('transcription')}")
		agi.set_variable("AGISTATUS", "success")
		agi.set_variable("TRANSCRIPT", result.get("transcription"))
	else:
		agi.verbose(f"Transcription failed: {result.get('error')}")
		agi.set_variable("AGISTATUS", "failure")

except Exception as e:
	agi.verbose(f"AGI Exception: {e}")
	agi.hangup()
