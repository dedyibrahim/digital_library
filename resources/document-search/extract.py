"""Bounded, local document extraction. No document data is sent to a service."""
import argparse
import io
import json
import os
import re
import sys
import time
import zipfile
from contextlib import closing
from pathlib import Path

import pypdfium2 as pdfium
import pytesseract
from defusedxml import ElementTree
from PIL import Image, ImageOps, ImageSequence

Image.MAX_IMAGE_PIXELS = 25_000_000
MAX_CHARS = 500_000
MAX_PAGES = 100


class Extractor:
    def __init__(self, languages, tessdata, tesseract):
        pytesseract.pytesseract.tesseract_cmd = tesseract
        self.languages = languages
        os.environ['TESSDATA_PREFIX'] = str(tessdata)
        self.config = '--psm 3'
        self.parts = []
        self.size = 0
        self.partial = False
        self.deadline = time.monotonic() + 480
        self.images = 0

    def add(self, text):
        text = re.sub(r'\s+', ' ', text.replace('\x00', '')).strip()
        if self.size + len(text) > MAX_CHARS:
            self.partial = True
        text = text[:max(0, MAX_CHARS - self.size)]
        if text:
            self.parts.append(text)
            self.size += len(text) + 1

    def full(self):
        if self.size >= MAX_CHARS or time.monotonic() >= self.deadline:
            self.partial = True
            return True
        return False

    def ocr(self, image):
        if self.full() or self.images >= MAX_PAGES:
            self.partial = True
            return
        self.images += 1
        image = ImageOps.exif_transpose(image).convert('RGB')
        image.thumbnail((4000, 4000))
        image = ImageOps.autocontrast(ImageOps.grayscale(image))
        try:
            self.add(pytesseract.image_to_string(
                image, lang=self.languages, config=self.config,
                timeout=max(1, min(45, self.deadline - time.monotonic())),
            ))
        except RuntimeError as error:
            if 'timeout' not in str(error).lower():
                raise
            self.partial = True

    def pdf(self, path):
        with pdfium.PdfDocument(path) as document:
            self.add(' '.join(str(value) for value in document.get_metadata_dict().values()))
            self.partial = len(document) > MAX_PAGES
            for index in range(min(len(document), MAX_PAGES)):
                if self.full():
                    break
                with closing(document[index]) as page:
                    with closing(page.get_textpage()) as textpage:
                        text = textpage.get_text_bounded()
                    self.add(text)
                    has_images = any(page.get_objects(filter=[pdfium.raw.FPDF_PAGEOBJ_IMAGE]))
                    if len(text.strip()) < 80 or has_images:
                        scale = min(200 / 72, 4000 / max(page.get_size()))
                        bitmap = page.render(scale=scale)
                        try:
                            self.ocr(bitmap.to_pil())
                        finally:
                            bitmap.close()

    def office(self, path, extension):
        prefixes = {'docx': ('word/document.xml', 'word/header', 'word/footer'),
                    'xlsx': ('xl/sharedStrings.xml', 'xl/worksheets/sheet'),
                    'pptx': ('ppt/slides/slide', 'ppt/notesSlides/notesSlide')}
        with zipfile.ZipFile(path) as archive:
            entries = archive.infolist()
            if len(entries) > 5000 or sum(entry.file_size for entry in entries) > 40_000_000:
                raise ValueError('Office archive exceeds safe extraction limits')
            for entry in entries:
                if self.full():
                    break
                name = entry.filename
                if name.endswith('.xml') and (name.startswith(prefixes[extension]) or name == 'docProps/core.xml'):
                    root = ElementTree.fromstring(archive.read(entry))
                    self.add(' '.join(root.itertext()))
                elif '/media/' in name and Path(name).suffix.lower() in {'.png', '.jpg', '.jpeg', '.tif', '.tiff', '.bmp', '.webp'}:
                    with Image.open(io.BytesIO(archive.read(entry))) as image:
                        self.ocr(image)

    def extract(self, path, name):
        extension = Path(name).suffix.lower().lstrip('.')
        if extension == 'pdf':
            self.pdf(path)
        elif extension in {'png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'}:
            with Image.open(path) as image:
                for index, frame in enumerate(ImageSequence.Iterator(image)):
                    if index >= MAX_PAGES or self.full():
                        self.partial = True
                        break
                    self.ocr(frame)
        elif extension in {'docx', 'xlsx', 'pptx'}:
            self.office(path, extension)
        elif extension in {'txt', 'csv', 'md', 'json', 'xml', 'log', 'tsv'}:
            with open(path, 'rb') as source:
                raw = source.read(2_000_001)
            self.partial = len(raw) > 2_000_000
            encoding = 'utf-16' if raw.startswith((b'\xff\xfe', b'\xfe\xff')) else 'utf-8-sig'
            self.add(raw[:2_000_000].decode(encoding, errors='replace'))
        else:
            return {'text': '', 'status': 'unsupported'}
        text = '\n'.join(self.parts)[:MAX_CHARS]
        return {'text': text, 'status': 'partial' if self.partial else ('ready' if text else 'empty')}


def main():
    parser = argparse.ArgumentParser()
    for name in ('file', 'name', 'tesseract', 'tessdata', 'languages'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        result = Extractor(args.languages, args.tessdata, args.tesseract).extract(args.file, args.name)
        print(json.dumps(result, ensure_ascii=True))
    except Exception:
        print('Document extraction failed (invalid file or unavailable OCR runtime).', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
