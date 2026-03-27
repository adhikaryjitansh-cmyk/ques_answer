import sys
import json
import os
import re
from pathlib import Path

# Solid fallbacks for Apache/PHP environments
if not os.environ.get("USERPROFILE"):
    os.environ["USERPROFILE"] = r"C:\Users\web Capital"

if not os.environ.get("MODELSCOPE_CACHE"):
    os.environ["MODELSCOPE_CACHE"] = r"C:\xampp3\htdocs\question_answer\storage\app\modelscope_cache"

from pdf2image import convert_from_path, pdfinfo_from_path
from paddleocr import PaddleOCR

def group_texts_to_qa(lines):
    """
    Heuristic-based Q&A grouping logic for PaddleOCR text lines.
    Looking for patterns like Q1, Q:, প্রশ্ন, Ans, উত্তর:, etc.
    """
    extracted_data = []
    current_q = ""
    current_a = ""
    current_chapter = None
    
    # Common markers
    q_markers = ["Q", "Q.", "Question", "প্রশ্ন", "প্রঃ", "Q:"]
    a_markers = ["Ans", "Answer", "উত্তর", "উঃ", "Ans:"]
    chapter_markers = ["Chapter", "অধ্যায়", "Unit"]

    is_collecting_answer = False

    for text in lines:
        text = text.strip()
        if not text: continue
        
        # Detect Chapter
        is_chapter = any(text.startswith(m) for m in chapter_markers)
        if is_chapter:
            current_chapter = text
            continue

        # Detect Question start
        # e.g., "1. What is..." or "প্রশ্ন: জীবন কী?"
        is_q_start = False
        # Starts with digits followed by dot/parenthesis
        if re.match(r'^\d+[\.\)]', text):
            is_q_start = True
        elif any(text.startswith(m) for m in q_markers):
            is_q_start = True
            
        # Detect Answer start
        is_a_start = any(text.startswith(m) for m in a_markers)

        if is_q_start:
            # Save previous if exists
            if current_q.strip():
                extracted_data.append({
                    "chapter": current_chapter,
                    "question": current_q.strip(),
                    "answer": current_a.strip() if current_a.strip() else "Answer not found",
                    "language": "bn/en"
                })
            current_q = text
            current_a = ""
            is_collecting_answer = False
            
        elif is_a_start:
            is_collecting_answer = True
            # Strip marker from start of answer
            clean_text = text
            for am in a_markers:
                if clean_text.startswith(am):
                    clean_text = clean_text[len(am):].lstrip(': ').strip()
                    break
            current_a += " " + clean_text
            
        else:
            # Contiguous text
            if is_collecting_answer:
                current_a += " " + text
            else:
                if current_q:
                    current_q += " " + text
                else:
                    # Floating text before any question? Might be a chapter or header
                    pass

    # Final wrap up
    if current_q.strip():
        extracted_data.append({
            "chapter": current_chapter,
            "question": current_q.strip(),
            "answer": current_a.strip() if current_a.strip() else "Answer not found",
            "language": "bn/en"
        })
        
    return extracted_data

def process_pdf(pdf_path):
    try:
        if not os.path.exists(pdf_path):
            raise FileNotFoundError(f"PDF not found at {pdf_path}")

        import logging
        logging.getLogger('ppocr').setLevel(logging.ERROR)
        
        # Initialize PaddleOCR with explicit version to find models
        ocr = PaddleOCR(use_angle_cls=True, lang='bn', ocr_version='PP-OCRv4')

        info = pdfinfo_from_path(pdf_path)
        total_pages = info["Pages"]

        for page_num in range(1, total_pages + 1):
            images = convert_from_path(pdf_path, size=(1280, None), first_page=page_num, last_page=page_num)

            page_lines = []

            for i, image in enumerate(images):
                temp_img_path = f"page_{page_num}_{i}.jpg"
                image.save(temp_img_path, 'JPEG')

                result = ocr.ocr(temp_img_path, cls=True)
                
                if result and result[0]:
                    for line in result[0]:
                        text = line[1][0]
                        page_lines.append(text)
                
                if os.path.exists(temp_img_path):
                    os.remove(temp_img_path)

            # Use heuristic to group OCR text into Questions and Answers
            parsed_data = group_texts_to_qa(page_lines)

            # Inform Laravel about progress
            progress_msg = {
                "type": "progress",
                "current": page_num,
                "total": total_pages,
                "data": parsed_data
            }
            
            print(json.dumps(progress_msg, ensure_ascii=False), flush=True)

    except Exception as e:
        print(json.dumps({"error": str(e)}), flush=True)
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No PDF path provided."}))
        sys.exit(1)
    
    process_pdf(sys.argv[1])
