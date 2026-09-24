<?php

return [
    'python' => env('DOCUMENT_SEARCH_PYTHON', storage_path('app/search-runtime/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python'))),
    'tesseract' => env('DOCUMENT_SEARCH_TESSERACT', PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Tesseract-OCR/tesseract.exe' : 'tesseract'),
    'tessdata' => env('DOCUMENT_SEARCH_TESSDATA', storage_path('app/ocr-tessdata')),
    'languages' => env('DOCUMENT_SEARCH_LANGUAGES', 'ind+eng'),
];
