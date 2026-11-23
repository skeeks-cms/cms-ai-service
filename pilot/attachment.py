"""Convert one explicitly selected chat attachment into model input."""
import base64
import io
import json
from pathlib import Path
import sys
from zipfile import ZipFile
from xml.etree import ElementTree

LIMIT = 24000


def extract(name, data):
    clipped = False
    suffix = Path(name).suffix.lower()
    if suffix in {".png", ".jpg", ".jpeg", ".webp"}:
        from PIL import Image
        with Image.open(io.BytesIO(data)) as image:
            if image.width * image.height > 24_000_000:
                raise ValueError("Слишком большое разрешение изображения.")
            image.thumbnail((1800, 1800))
            out = io.BytesIO()
            image.convert("RGB").save(out, format="JPEG", quality=85)
            return {"image": base64.b64encode(out.getvalue()).decode()}
    if suffix == ".pdf":
        import pymupdf
        with pymupdf.open(stream=data, filetype="pdf") as doc:
            chunks = []
            size = 0
            for page in doc:
                part = page.get_text()
                chunks.append(part)
                size += len(part)
                if size > LIMIT:
                    break
            text = "\n".join(chunks)
            if not text.strip():
                return {"notice": "PDF не содержит извлекаемого текста. Для анализа скана приложите страницу как изображение."}
    elif suffix in {".docx", ".xlsx"}:
        with ZipFile(io.BytesIO(data)) as archive:
            if sum(i.file_size for i in archive.infolist()) > 20_000_000:
                raise ValueError("Слишком большой распакованный документ.")
            if suffix == ".docx":
                xml = ElementTree.fromstring(archive.read("word/document.xml"))
                text = "\n".join(
                    "".join(t.text or "" for t in p.findall(".//{*}t")) for p in xml.findall(".//{*}p"))
            else:
                import openpyxl
                book = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
                try:
                    rows = []
                    size = 0
                    for sheet in book:
                        rows.append(sheet.title)
                        clipped |= (sheet.max_column or 0)>100 or (sheet.max_row or 0)>1500
                        for row in sheet.iter_rows(max_col=min(sheet.max_column or 1,100), max_row=min(sheet.max_row or 1,1500), values_only=True):
                            line = "\t".join(str(cell) if cell is not None else "" for cell in row)
                            rows.append(line)
                            size += len(line)+1
                            if size > LIMIT:
                                break
                        if size > LIMIT:
                            break
                    text = "\n".join(rows)
                finally:
                    book.close()
    elif suffix in {".txt",".md",".csv",".tsv",".json",".xml",".yaml",".yml",".html",".css",".js",".php",".py",".log",".sql",".svg"}:
        text = data.decode("utf-8-sig")
    else:
        return {"notice": "Формат доступен для скачивания. Извлечение содержимого этого формата не поддерживается."}
    return {"text": text[:LIMIT], "truncated": clipped or len(text)>LIMIT}


try:
    payload = json.load(sys.stdin)
    result = extract(payload["name"],base64.b64decode(payload["base64"],validate=True))
except Exception:
    result = {"notice": "Не удалось прочитать содержимое вложения. Проверьте формат и кодировку."}
print(json.dumps(result,ensure_ascii=True))
