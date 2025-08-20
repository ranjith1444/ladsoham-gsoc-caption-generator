#!/usr/bin/env python3
"""
Standalone Python script for caption generation within Drupal module.
This script can be called directly from PHP without external API dependencies.
"""

import sys
import os
import json
import logging
from pathlib import Path

# Add the python directory to Python path
# The script is in: web/modules/caption_generator/
# Python modules are in: python/
# So we need to go up 3 levels: ../../../python
script_dir = os.path.dirname(os.path.abspath(__file__))
python_dir = os.path.join(script_dir, '..', '..', '..', 'python')
sys.path.insert(0, python_dir)

try:
    from vision import ImageClassifier
    from caption import ImageCaptioner
    from enhancer import CaptionEnhancer
except ImportError as e:
    print(json.dumps({"error": f"Failed to import required modules: {str(e)}"}))
    sys.exit(1)

def setup_logging():
    """Setup logging for the script."""
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
    )
    return logging.getLogger(__name__)

def generate_caption(image_path, max_length=50):
    """
    Generate caption for the given image.
    
    Args:
        image_path (str): Path to the image file
        max_length (int): Maximum length for the caption
        
    Returns:
        dict: JSON response with caption and labels
    """
    logger = setup_logging()
    
    try:
        # Validate image path
        if not os.path.exists(image_path):
            return {"error": f"Image file not found: {image_path}"}
        
        # Initialize models
        logger.info("Initializing models...")
        classifier = ImageClassifier()
        captioner = ImageCaptioner()
        enhancer = CaptionEnhancer()
        
        # Generate classification
        logger.info("Classifying image...")
        classification_result = classifier.classify_image(image_path, top_k=5)
        
        # Generate base caption
        logger.info("Generating base caption...")
        caption_result = captioner.generate_caption(image_path, max_length=max_length)
        
        # Enhance caption
        logger.info("Enhancing caption...")
        enhanced_result = enhancer.enhance_caption(caption_result, classification_result)
        
        # Prepare response
        response = {
            "base_caption": caption_result.get("caption", ""),
            "labels": [label["label"] for label in classification_result],
            "enhanced_caption": enhanced_result.get("enhanced_caption", "Enhancement failed."),
            "success": True
        }
        
        logger.info(f"Generated caption: {response['enhanced_caption']}")
        return response
        
    except Exception as e:
        logger.error(f"Error during caption generation: {str(e)}")
        return {"error": str(e), "success": False}

def main():
    """Main function to handle command line arguments."""
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: python3 caption_generator_python.py <image_path> [max_length]"}))
        sys.exit(1)
    
    image_path = sys.argv[1]
    max_length = int(sys.argv[2]) if len(sys.argv) > 2 else 50
    
    result = generate_caption(image_path, max_length)
    print(json.dumps(result))

if __name__ == "__main__":
    main() 