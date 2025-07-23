from bs4 import BeautifulSoup
import jdatetime
import json

# --- Configuration ---
# HTML_FILE_PATH = "PersianDates.html" # Set in if __name__ == "__main__"
# TARGET_PERSIAN_YEAR = jdatetime.date.today().year # Set in if __name__ == "__main__"

PERSIAN_MONTHS = {
    "فروردین": 1, "اردیبهشت": 2, "خرداد": 3,
    "تیر": 4, "مرداد": 5, "شهریور": 6, # jdatetime uses "مرداد"
    "مهر": 7, "آبان": 8, "آذر": 9,
    "دی": 10, "بهمن": 11, "اسفند": 12
}

def parse_persian_events(html_file_path, persian_year):
    all_events = []
    holidays_only = []

    print(f"DEBUG: Starting to parse '{html_file_path}' for year {persian_year}")

    try:
        with open(html_file_path, 'r', encoding='utf-8') as f:
            html_content = f.read()
        print(f"DEBUG: Successfully read {len(html_content)} characters from '{html_file_path}'.")
        if not html_content.strip():
            print("DEBUG: Warning - HTML file content is empty or whitespace only.")
            return [], []
    except FileNotFoundError:
        print(f"DEBUG: Error - HTML file '{html_file_path}' not found.")
        return [], []
    except Exception as e:
        print(f"DEBUG: Error reading file '{html_file_path}': {e}")
        return [], []

    soup = BeautifulSoup(html_content, 'html.parser')
    if not soup.body:
        print("DEBUG: Warning - BeautifulSoup could not find a <body> tag. The HTML might be malformed or very minimal.")

    month_block_class_primary = 'YearlyCalendarListItem_root__eventList__9jpnW'
    month_blocks = soup.find_all('div', class_=month_block_class_primary)
    print(f"DEBUG: Found {len(month_blocks)} month blocks using primary class '{month_block_class_primary}'.")

    if not month_blocks:
        print(f"DEBUG: CRITICAL - Could not find any month blocks using known class '{month_block_class_primary}'.")
        print(f"DEBUG: Please inspect your HTML for the main 'div' that wraps each month's events and update the script's class name.")
        return [], []
    
    month_counter = 0
    for month_block_index, month_block in enumerate(month_blocks):
        month_counter += 1
        print(f"\nDEBUG: --- Processing Month Block {month_counter} (Index {month_block_index}) ---")

        # Extract month name
        # MODIFIED: Use the more stable 'MuiTypography-h2' class for the header
        month_header_tag = month_block.find('h2', class_='MuiTypography-h2') 
        
        if not month_header_tag:
            print(f"DEBUG: Month Block {month_counter}: Month header (h2 with class 'MuiTypography-h2') NOT FOUND.")
            any_h2 = month_block.find('h2')
            if any_h2:
                print(f"DEBUG: Month Block {month_counter}: Found an h2, but class 'MuiTypography-h2' mismatch. Text: '{any_h2.get_text(strip=True)}', Classes: '{any_h2.get('class')}'")
            else:
                print(f"DEBUG: Month Block {month_counter}: No h2 tag found at all in this month block.")
            continue
        
        month_name_full = month_header_tag.get_text(strip=True)
        print(f"DEBUG: Month Block {month_counter}: Found month header text: '{month_name_full}'")
        
        persian_month_name_extracted = ""
        try:
            parts = month_name_full.split('ماه ')
            if len(parts) > 1:
                # Normalize common month name variations if necessary
                persian_month_name_extracted = parts[1].strip().replace("اَمرداد", "مرداد").replace("مرداد", "مرداد") # Ensure مرداد is target
            else:
                # Fallback: try to find any known month name in the full header string
                # This is a bit riskier if month names are substrings of other words, but often works.
                normalized_month_name_full = month_name_full.replace("اَمرداد", "مرداد")
                for known_month_key in PERSIAN_MONTHS.keys():
                    if known_month_key in normalized_month_name_full:
                        persian_month_name_extracted = known_month_key # already normalized as it's from our dict keys
                        break
                if not persian_month_name_extracted:
                    print(f"DEBUG: Month Block {month_counter}: Could not parse specific month name from '{month_name_full}'. The string 'ماه ' might be missing or format is unexpected.")
                    continue
        except IndexError: # Should not happen if parts has len > 1
            print(f"DEBUG: Month Block {month_counter}: Error splitting month name from '{month_name_full}'.")
            continue

        persian_month_number = PERSIAN_MONTHS.get(persian_month_name_extracted)
        if persian_month_number is None:
            print(f"DEBUG: Month Block {month_counter}: Unknown or unnormalized Persian month name '{persian_month_name_extracted}' (parsed from '{month_name_full}'). Skipping block.")
            continue
        print(f"DEBUG: Month Block {month_counter}: Parsed month: '{persian_month_name_extracted}' (Number: {persian_month_number})")

        # Find all event items within this month
        event_items_container = month_block.find('div', class_='EventList_root__events__container__6bHdH')
        if not event_items_container:
            print(f"DEBUG: Month Block {month_counter} ({persian_month_name_extracted}): Event items container (div with class 'EventList_root__events__container__6bHdH') NOT FOUND.")
            continue
        print(f"DEBUG: Month Block {month_counter} ({persian_month_name_extracted}): Found event items container.")
            
        event_items = event_items_container.find_all('div', class_='EventListItem_root__pHV2b')
        print(f"DEBUG: Month Block {month_counter} ({persian_month_name_extracted}): Found {len(event_items)} potential event items (div with class 'EventListItem_root__pHV2b').")

        if not event_items:
            print(f"DEBUG: Month Block {month_counter} ({persian_month_name_extracted}): No individual event items found with class 'EventListItem_root__pHV2b'.")

        for item_idx, item in enumerate(event_items):
            # print(f"DEBUG:   Processing event item {item_idx + 1} in {persian_month_name_extracted}") # Reduced verbosity here
            date_span = item.find('span', class_='EventListItem_root__date__UUgtf')
            event_span = item.find('span', class_='EventListItem_root__event__XrjoV')
            other_base_span = item.find('span', class_='EventListItem_root__otherBase__8Sksv')

            if not date_span:
                print(f"DEBUG:     Event item {item_idx + 1}: Date span (span.EventListItem_root__date__UUgtf) NOT FOUND.")
            if not event_span:
                print(f"DEBUG:     Event item {item_idx + 1}: Event span (span.EventListItem_root__event__XrjoV) NOT FOUND.")
            
            if not date_span or not event_span:
                print(f"DEBUG:     Skipping event item {item_idx + 1} due to missing date or event span.")
                continue

            persian_date_text = date_span.get_text(strip=True)
            event_description = event_span.get_text(strip=True)
            other_base_info = other_base_span.get_text(strip=True) if other_base_span else None
            # print(f"DEBUG:     Extracted Raw: Date='{persian_date_text}', Event='{event_description}'") # Reduced verbosity

            try:
                persian_day_str = persian_date_text.split(' ')[0]
                persian_day = int(persian_day_str)
            except (ValueError, IndexError):
                print(f"DEBUG:     Warning: Could not parse day from '{persian_date_text}'. Skipping item.")
                continue

            is_holiday = 'EventListItem_root__date__holiday__FY_JU' in date_span.get('class', [])

            gregorian_date_str = None
            gregorian_date_obj = None
            try:
                if not (1 <= persian_day <= 31):
                     raise ValueError(f"Persian day {persian_day} is out of typical range 1-31.")
                j_date = jdatetime.date(persian_year, persian_month_number, persian_day)
                g_date = j_date.togregorian()
                gregorian_date_str = g_date.strftime('%Y-%m-%d')
                gregorian_date_obj = g_date
            except ValueError as e:
                print(f"DEBUG:     Warning: Could not convert date {persian_day} {persian_month_name_extracted} {persian_year}: {e}")
            except Exception as e:
                 print(f"DEBUG:     Error converting date {persian_day} {persian_month_name_extracted} {persian_year}: {e}")

            event_data = {
                "persian_date_str": f"{persian_day} {persian_month_name_extracted}",
                "persian_year": persian_year,
                "persian_month_number": persian_month_number,
                "persian_day": persian_day,
                "event_description": event_description,
                "other_base_info": other_base_info,
                "is_holiday": is_holiday,
                "gregorian_date_str": gregorian_date_str,
                "gregorian_year": gregorian_date_obj.year if gregorian_date_obj else None,
                "gregorian_month": gregorian_date_obj.month if gregorian_date_obj else None,
                "gregorian_day": gregorian_date_obj.day if gregorian_date_obj else None,
            }
            
            all_events.append(event_data)
            if is_holiday:
                holidays_only.append(event_data)
            # print(f"DEBUG:     Successfully processed and stored event: {event_description}") # Reduced verbosity
                
    if not all_events and month_blocks:
        print("DEBUG: Found month blocks, but no events were successfully extracted from them. Check individual event item parsing or class names for event containers/items.")

    return all_events, holidays_only

if __name__ == "__main__":
    HTML_FILE_PATH = r"C:\xampp\htdocs\public_html\hh\PersianDates.html"
    TARGET_PERSIAN_YEAR = 1404

    print(f"Attempting to read from your actual file: '{HTML_FILE_PATH}'")
    print(f"Using Persian Year: {TARGET_PERSIAN_YEAR} for Gregorian conversion.\n")

    all_events, holidays = parse_persian_events(HTML_FILE_PATH, TARGET_PERSIAN_YEAR)

    # Write all events to JSON file
    all_events_file = r"C:\xampp\htdocs\public_html\hh\all_events.json"
    with open(all_events_file, "w", encoding="utf-8") as f:
        json.dump(all_events, f, ensure_ascii=False, indent=2)
    print(f"\nAll events written to '{all_events_file}'.")

    # Write holidays only to JSON file
    holidays_file = r"C:\xampp\htdocs\public_html\hh\holidays.json"
    with open(holidays_file, "w", encoding="utf-8") as f:
        json.dump(holidays, f, ensure_ascii=False, indent=2)
    print(f"Holidays written to '{holidays_file}'.")