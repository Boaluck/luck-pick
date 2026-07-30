#!/usr/bin/env python3
"""BOAT RACE公式サイトから当日の開催情報を取得する。"""

from __future__ import annotations

import argparse
import json
import os
import re
import tempfile
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qs, urlparse
from zoneinfo import ZoneInfo

import requests
from bs4 import BeautifulSoup


JST = ZoneInfo("Asia/Tokyo")
SOURCE_BASE_URL = "https://www.boatrace.jp/owpc/pc/race/index"
VENUES = {
    "01": "桐生", "02": "戸田", "03": "江戸川", "04": "平和島",
    "05": "多摩川", "06": "浜名湖", "07": "蒲郡", "08": "常滑",
    "09": "津", "10": "三国", "11": "びわこ", "12": "住之江",
    "13": "尼崎", "14": "鳴門", "15": "丸亀", "16": "児島",
    "17": "宮島", "18": "徳山", "19": "下関", "20": "若松",
    "21": "芦屋", "22": "福岡", "23": "唐津", "24": "大村",
}


def fetch_page(source_url: str) -> str:
    response = requests.get(
        source_url,
        headers={
            "User-Agent": "LuckPick-RaceTitle-Updater/2.0 (+https://github.com/Boaluck/luck-pick)",
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Language": "ja,en;q=0.7",
        },
        timeout=(10, 30),
    )
    response.raise_for_status()
    content_type = response.headers.get("Content-Type", "")
    if "text/html" not in content_type.lower():
        raise RuntimeError(f"HTML以外の応答を受信しました: {content_type}")
    return response.text


def parse_races(html: str, target_yyyymmdd: str) -> list[dict[str, str]]:
    """当日分のraceindexリンクだけを抽出し、前日発売欄を除外する。"""
    soup = BeautifulSoup(html, "html.parser")
    races: dict[str, dict[str, str]] = {}

    for link in soup.select('a[href*="/owpc/pc/race/raceindex?"]'):
        query = parse_qs(urlparse(link.get("href", "")).query)
        code = query.get("jcd", [""])[0].zfill(2)
        date = query.get("hd", [""])[0]
        title = re.sub(r"\s+", " ", link.get_text(" ", strip=True))

        if date != target_yyyymmdd or code not in VENUES or not title:
            continue
        races.setdefault(code, {
            "stadiumCode": code,
            "stadiumName": VENUES[code],
            "raceTitle": title,
        })

    return [races[code] for code in sorted(races)]


def write_json_atomic(output: Path, payload: dict) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    json_text = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
    file_descriptor, temporary_name = tempfile.mkstemp(
        dir=output.parent, prefix=f".{output.name}.", suffix=".tmp", text=True
    )
    try:
        with os.fdopen(file_descriptor, "w", encoding="utf-8") as temporary:
            temporary.write(json_text)
            temporary.flush()
            os.fsync(temporary.fileno())
        os.replace(temporary_name, output)
    except BaseException:
        try:
            os.unlink(temporary_name)
        except FileNotFoundError:
            pass
        raise


def parse_args() -> argparse.Namespace:
    project_root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--date",
        help="取得日（YYYY-MM-DD）。省略時は日本時間の当日",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=project_root / "data" / "today-races.json",
        help="JSON出力先",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    now = datetime.now(JST)
    try:
        target_date = datetime.strptime(args.date, "%Y-%m-%d").date() if args.date else now.date()
    except ValueError as error:
        raise SystemExit("--date は YYYY-MM-DD 形式で指定してください") from error

    target_yyyymmdd = target_date.strftime("%Y%m%d")
    source_url = f"{SOURCE_BASE_URL}?hd={target_yyyymmdd}"
    html = fetch_page(source_url)
    races = parse_races(html, target_yyyymmdd)
    if not races:
        raise RuntimeError("開催場とレース名を1件も抽出できませんでした")

    payload = {
        "date": target_date.isoformat(),
        "updatedAt": now.isoformat(timespec="seconds"),
        "source": source_url,
        "status": "ok",
        "races": races,
    }
    write_json_atomic(args.output, payload)
    print(f"更新成功: {target_date.isoformat()} / {len(races)}場")


if __name__ == "__main__":
    main()
