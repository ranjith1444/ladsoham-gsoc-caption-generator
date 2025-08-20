import re
import os
from typing import List, Dict, Optional, Union

try:
	import google.generativeai as genai  # type: ignore
	exists_google_genai = True
except Exception:
	exists_google_genai = False

from transformers import GPT2LMHeadModel, GPT2Tokenizer

class CaptionEnhancer:
	def __init__(self):
		pass

	def enhance_caption(
		self,
		base_caption: Union[str, Dict[str, str]],
		classification_labels: List[Dict[str, Union[str, float]]],
		min_len: int = 30,
		max_len: int = 60,
		add_emojis: bool = False,
		gemini_api_key: Optional[str] = None,
	):
		try:
			# Support both dict and string caption input
			if isinstance(base_caption, dict):
				caption = base_caption.get('caption', '')
			else:
				caption = base_caption

			labels = [item.get('label') for item in classification_labels if isinstance(item, dict) and item.get('label')]
			labels = [lbl for lbl in labels if isinstance(lbl, str)]

			# If Gemini is available and we have an API key, use it
			api_key_to_use = (gemini_api_key or os.getenv('GEMINI_API_KEY') or '').strip()
			if exists_google_genai and api_key_to_use:
				try:
					genai.configure(api_key=api_key_to_use)
					label_text = ", ".join(labels)
					emoji_instruction = (
						"Include relevant emojis that match the tone and context of the caption. Place them naturally in the text."
						if add_emojis else
						"Do not include any emojis."
					)
					prompt = (
						f"You are an expert image captioner. Using the detected image labels and the initial caption, create an improved, vivid, and natural-sounding caption. "
						f"Make sure it is between {min_len} and {max_len} characters.\n"
						f"{emoji_instruction}\n\n"
						f"Detected Labels: {label_text}\n"
						f"Original Caption: {caption}"
					)
					model = genai.GenerativeModel("gemini-1.5-flash")
					response = model.generate_content(prompt)
					gemini_text = (response.text or "").strip()
					if gemini_text:
						gemini_text = self._post_process(gemini_text, min_len, max_len, add_emojis)
						return {
							'original_caption': caption,
							'enhanced_caption': gemini_text,
							'used_labels': labels[:3],
							'source': 'gemini'
						}
				except Exception:
					# Fall back to heuristic
					pass

			# Heuristic fallback enhancer
			caption = re.sub(r'^(a photo of|an image of|a picture of)\s*', '', caption, flags=re.IGNORECASE)
			if caption:
				caption = caption[0].upper() + caption[1:]

			relevant_labels = self._filter_relevant_labels(labels, caption)
			if relevant_labels:
				top_label = relevant_labels[0].replace('_', ' ')
				if top_label.lower() not in caption.lower():
					caption = f"{caption.rstrip('.')}. This appears to be related to {top_label}."

			caption = self._clean_caption(caption)
			caption = self._post_process(caption, min_len, max_len, add_emojis)

			return {
				'original_caption': base_caption if isinstance(base_caption, str) else base_caption.get('caption', ''),
				'enhanced_caption': caption,
				'used_labels': relevant_labels[:3] if 'relevant_labels' in locals() else [],
				'source': 'huggingface'
			}

		except Exception as e:
			return {'error': f'Caption enhancement failed: {str(e)}'}

	def _post_process(self, caption: str, min_len: int, max_len: int, add_emojis: bool) -> str:
		caption = caption.strip()
		# Enforce max length
		if max_len > 0 and len(caption) > max_len:
			caption = caption[:max_len].rstrip()
		# Ensure minimum length by simple padding with context if needed
		if len(caption) < min_len and min_len > 0:
			caption = caption.ljust(min_len, '.')
		# Optional emoji addition for fallback or post-Gemini normalization
		if add_emojis and not any(ch in caption for ch in ['😀','😊','😍','🤩','😎','✨','🎉','🌟','🔥','📸','🏞️','🌈','🌸','🌅','🐾','🍃','🍁']):
			caption += ' ✨'
		# Clean punctuation
		caption = self._clean_caption(caption)
		return caption

	def _filter_relevant_labels(self, labels, caption):
		generic_labels = ['object', 'item', 'thing', 'stuff', 'other']
		filtered_labels = [label for label in labels if label and label.lower() not in generic_labels]
		caption_words = caption.lower().split()
		relevant_labels = []
		for label in filtered_labels:
			label_words = label.lower().replace('_', ' ').split()
			if not any(word in caption_words for word in label_words):
				relevant_labels.append(label)
		return relevant_labels[:5]

	def _clean_caption(self, caption):
		caption = re.sub(r'\s+', ' ', caption)
		if caption and not caption.endswith(('.', '!', '?')):
			caption += '.'
		caption = re.sub(r'\.{2,}', '.', caption)
		return caption.strip()


if __name__ == "__main__":
	enhancer = CaptionEnhancer()
	test_caption = "a young boy in a yellow shirt and blue jeans"
	test_labels = [
		{"label": "sweatshirt", "confidence": 0.9},
		{"label": "sunglass", "confidence": 0.8},
		{"label": "maraca", "confidence": 0.7},
		{"label": "jersey, T-shirt, tee shirt", "confidence": 0.6},
		{"label": "sunglasses, dark glasses, shades", "confidence": 0.5}
	]
	result = enhancer.enhance_caption(test_caption, test_labels, 30, 60, True)
	print("=== ENHANCER TEST OUTPUT ===")
	print("Original Caption:", result['original_caption'])
	print("Enhanced Caption:", result['enhanced_caption'])
	print("Used Labels:", result['used_labels'])
	print("Source:", result.get('source'))
	print("============================") 