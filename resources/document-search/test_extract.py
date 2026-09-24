import os
import tempfile
import unittest
import zipfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont
from extract import Extractor


ROOT = Path(__file__).resolve().parents[2]


class ExtractionTest(unittest.TestCase):
    def extractor(self):
        return Extractor('ind+eng', ROOT / 'storage/app/ocr-tessdata',
                         os.environ.get('DOCUMENT_SEARCH_TESSERACT',
                                        'C:/Program Files/Tesseract-OCR/tesseract.exe' if os.name == 'nt' else 'tesseract'))

    def test_text_and_unsupported_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'sample.txt'
            path.write_text('Sertifikat tanah Nusantara', encoding='utf-8')
            self.assertEqual(self.extractor().extract(path, 'sample.txt'),
                             {'text': 'Sertifikat tanah Nusantara', 'status': 'ready'})
            self.assertEqual(self.extractor().extract(path, 'sample.mp4')['status'], 'unsupported')

    def test_office_documents(self):
        with tempfile.TemporaryDirectory() as directory:
            for extension, part in [('docx', 'word/document.xml'), ('xlsx', 'xl/sharedStrings.xml'), ('pptx', 'ppt/slides/slide1.xml')]:
                path = Path(directory) / ('sample.' + extension)
                with zipfile.ZipFile(path, 'w') as archive:
                    archive.writestr(part, '<root><text>Kontrak Nusantara</text></root>')
                result = self.extractor().extract(path, path.name)
                self.assertIn('Kontrak Nusantara', result['text'])
                self.assertEqual('ready', result['status'])

    def test_xml_entities_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'unsafe.docx'
            with zipfile.ZipFile(path, 'w') as archive:
                archive.writestr('word/document.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>')
            with self.assertRaises(Exception):
                self.extractor().extract(path, path.name)

    def test_scanned_image_and_pdf_use_real_ocr(self):
        if not (ROOT / 'storage/app/ocr-tessdata/ind.traineddata').is_file():
            self.skipTest('Install local OCR language models first')
        with tempfile.TemporaryDirectory() as directory:
            image = Image.new('RGB', (2000, 500), 'white')
            font = ImageFont.truetype('C:/Windows/Fonts/arial.ttf' if os.name == 'nt' else 'DejaVuSans.ttf', 70)
            ImageDraw.Draw(image).text((80, 160), 'SERTIFIKAT TANAH NUSANTARA', font=font, fill='black')
            for extension in ['png', 'pdf']:
                path = Path(directory) / ('scan.' + extension)
                image.save(path)
                result = self.extractor().extract(path, path.name)
                self.assertEqual('ready', result['status'])
                self.assertIn('SERTIFIKAT TANAH NUSANTARA', result['text'].upper())

    def test_corrupt_pdf_fails_and_truncation_is_explicit(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'broken.pdf'
            path.write_bytes(b'not a PDF')
            with self.assertRaises(Exception):
                self.extractor().extract(path, path.name)
        extractor = self.extractor()
        extractor.add('a' * 600_000)
        self.assertTrue(extractor.partial)
        self.assertEqual(500_000, len(extractor.parts[0]))


if __name__ == '__main__':
    unittest.main()
