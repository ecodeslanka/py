#!/usr/bin/env python3
"""
stock_parse.py  —  Parse LeverEDGE Current Stock Report
Usage: python3 stock_parse.py <path_to_xlsx>
Output: JSON
"""
import sys, json
import pandas as pd
import numpy as np
from datetime import datetime

def safe_str(v):
    if v is None or (isinstance(v, float) and np.isnan(v)):
        return ''
    return str(v).strip()

def safe_float(v):
    try:
        if v is None or (isinstance(v, float) and np.isnan(v)):
            return 0.0
        return float(v)
    except:
        return 0.0

def safe_int(v):
    try:
        if v is None or (isinstance(v, float) and np.isnan(v)):
            return 0
        return int(float(v))
    except:
        return 0

def fmt_date(v):
    if v is None:
        return ''
    if isinstance(v, (datetime, pd.Timestamp)):
        return v.strftime('%Y-%m-%d')
    try:
        return pd.to_datetime(v).strftime('%Y-%m-%d')
    except:
        return ''

def main():
    if len(sys.argv) < 2:
        print(json.dumps({'success': False, 'error': 'No file path provided'}))
        sys.exit(1)

    fpath = sys.argv[1]

    try:
        # Read raw for meta info
        raw = pd.read_excel(fpath, sheet_name=0, header=None, nrows=20)
    except Exception as e:
        print(json.dumps({'success': False, 'error': f'Cannot open file: {str(e)}'}))
        sys.exit(1)

    # Extract meta
    rs_name     = ''
    report_date = ''
    header_row  = 17  # default

    for i in range(len(raw)):
        row = raw.iloc[i].tolist()
        row_str = [safe_str(c) for c in row]
        full    = ' '.join(row_str)

        if 'RS Name:' in full:
            for j, c in enumerate(row_str):
                if 'RS Name:' in c and j+1 < len(row_str):
                    rs_name = row_str[j+1]
                    break

        if 'Date:' in full and not report_date:
            for j, c in enumerate(row_str):
                if c == 'Date:' and j+1 < len(row_str) and row_str[j+1]:
                    try:
                        dt = pd.to_datetime(row_str[j+1], dayfirst=True)
                        report_date = dt.strftime('%Y-%m-%d')
                    except:
                        pass
                    break

        if 'Sr No' in row_str:
            header_row = i
            break

    # Read actual data
    try:
        df = pd.read_excel(fpath, sheet_name=0, header=header_row, dtype=str)
    except Exception as e:
        print(json.dumps({'success': False, 'error': f'Parse error: {str(e)}'}))
        sys.exit(1)

    # Normalize columns
    df.columns = [safe_str(c) for c in df.columns]

    # Drop fully-null rows and trailer rows
    df = df.dropna(how='all')
    # Keep only rows where 'Sr No' is numeric
    mask = df['Sr No'].apply(lambda v: str(v).strip().replace('.0','').isdigit() if pd.notna(v) else False)
    df = df[mask].copy()

    rows = []
    for _, r in df.iterrows():
        expiry_date_raw = r.get('Expiry Date', '')
        try:
            ed = pd.to_datetime(expiry_date_raw, errors='coerce')
            expiry_date = ed.strftime('%Y-%m-%d') if pd.notna(ed) else ''
        except:
            expiry_date = ''

        rows.append({
            'sr_no':          safe_int(r.get('Sr No', 0)),
            'division':       safe_str(r.get('Division', '')),
            'basepack_code':  safe_str(r.get('Basepack Code', '')),
            'sku7':           safe_str(r.get('SKU7', '')).replace('.0',''),
            'product_name':   safe_str(r.get('Product Name', '')),
            'location':       safe_str(r.get('Location', '')),
            'pkm':            safe_str(r.get('PKM', '')).replace('.0',''),
            'batch_code':     safe_str(r.get('Batch Code', '')),
            'expiry_month':   safe_str(r.get('Expiry Month', '')).replace('.0',''),
            'expiry_date':    expiry_date,
            'days_to_expire': safe_int(r.get('No of Days to Expire', 0)),
            'upc':            safe_int(r.get('UPC', 0)),
            'units':          safe_int(r.get('Units', 0)),
            'stocks_in_days': safe_str(r.get('Stocks in Days', '')),
            'pur_rate':       safe_float(r.get('Pur.Rate', 0)),
            'pur_rate_tax':   safe_float(r.get('Pur.Rate + Tax', 0)),
            'tur':            safe_float(r.get('TUR', 0)),
            'mrp':            safe_float(r.get('MRP', 0)),
            'cur_stk_value':  safe_float(r.get('Cur.Stk Value', 0)),
            'tonnage':        safe_float(r.get('Tonnage', 0)),
        })

    print(json.dumps({
        'success':     True,
        'rs_name':     rs_name,
        'report_date': report_date,
        'row_count':   len(rows),
        'rows':        rows,
    }))

if __name__ == '__main__':
    main()
