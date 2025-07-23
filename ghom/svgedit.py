import xml.etree.ElementTree as ET
import re
import os
import json
import mysql.connector
from typing import List, Dict, cast
from pathlib import Path

# ===================================================================================
#
#              SVG PROCESSOR & DATABASE SYNC (FINAL)
#
# ===================================================================================

# --- 1. SCRIPT CONFIGURATION ---
LAYERS_TO_PROCESS = {
    'GFRC': 'GC',
    'GLASS': 'GL',
    'Bazshow': 'BZ',
    'Default': None,
    'Curtainwall': 'CU'
}
DB_CONFIG = {
    'host': 'localhost',
    'database': 'hpc_ghom',
    'user': 'root',
    'password': ''
}
CONFIG_FILE = 'regionToZoneMap.json'
GROUP_CONFIG_FILE = 'svgGroupConfig.json'
INPUT_FOLDER = r"C:\xampp\htdocs\public_html\ghom\svg"
OUTPUT_FOLDER = r"C:\xampp\htdocs\public_html\ghom\processed"
VALID_AXIS_LETTERS = set('ABCDEFGHIJKLMNOPQRSTUVWXY')
VALID_AXIS_RANGE = range(-2, 25)  # -2 to 24 inclusive

# --- 2. HELPER FUNCTIONS ---
# (get_svg_context, get_contractor_initials, get_short_filename_prefix, etc. are unchanged)


def get_svg_context(svg_filename: str, config_data: dict, group_config_data: dict) -> dict:
    for region_key, zones in config_data.items():
        if isinstance(zones, list):
            for zone in zones:
                if zone.get('svgFile') == svg_filename:
                    details = group_config_data.get(region_key, {})
                    return {'contractor': details.get('contractor'), 'block': details.get('block')}
    return {'contractor': 'Unknown', 'block': 'Unknown'}


def get_contractor_initials(contractor_name: str) -> str:
    if not contractor_name:
        return 'XX'
    words = contractor_name.split()
    return (words[0][0] + words[1][0]).upper() if len(words) > 1 else words[0][:2].upper()


def get_short_filename_prefix(path: str) -> str:
    """Generates a short prefix like 'Z01' from a filename."""
    name = Path(path).stem.replace('Zone', 'Z')
    m = re.search(r'Z?(\d+)', name)
    return f"Z{m.group(1)}" if m else name[:3].upper()


def parse_path_coordinates(path_d: str) -> list:
    """Extracts all coordinate pairs from a path's 'd' attribute."""
    if not path_d:
        return []
    return [(float(x), float(y)) for _, x, y in re.findall(r'([ML])\s*([\d.-]+)\s*,\s*([\d.-]+)', path_d)]


def get_rectangle_center(coords: list) -> tuple:
    """Calculates the geometric center of a list of coordinates."""
    if not coords:
        return (0, 0)
    x_coords, y_coords = [p[0] for p in coords], [p[1] for p in coords]
    return ((min(x_coords) + max(x_coords)) / 2, (min(y_coords) + max(y_coords)) / 2)


def get_simple_path_length(path_d: str) -> float:
    """
    Calculates the pixel length of a simple 'M x,y L x,y' SVG <path> string.
    FINAL, ROBUST VERSION: Uses string manipulation instead of regex for reliability.
    """
    if not path_d:
        return 0.0

    try:
        # 1. Clean the string by removing command letters and normalizing separators
        cleaned_d = path_d.strip().upper()
        cleaned_d = cleaned_d.replace('M', ' ').replace(
            'L', ' ').replace(',', ' ')

        # 2. Split into a list of coordinate parts and filter out any empty strings
        coords = [p for p in cleaned_d.split(' ') if p]

        # 3. If we have exactly 4 coordinate parts, we have a simple line
        if len(coords) == 4:
            x1, y1, x2, y2 = map(float, coords)
            return ((x2 - x1)**2 + (y2 - y1)**2)**0.5
    except (ValueError, TypeError):
        # If conversion to float fails for any reason, return 0.0
        return 0.0

    # Return 0 if the path format was not a simple line with 4 coordinates
    return 0.0


def is_valid_axis_label(text: str) -> bool:
    """
    NEW: Checks if text matches the defined axis label rules (A-Y or -2 to 24).
    This prevents dimension numbers like '7800' from being included.
    """
    text = text.strip().upper()

    # Rule 1: Must be a single capital letter from A to Y
    if len(text) == 1 and 'A' <= text <= 'Y':
        return True

    # Rule 2: Must be a number within the specified range
    try:
        if -2 <= int(text) <= 24:
            return True
    except ValueError:
        # It's not a valid integer, so it fails this rule
        pass

    return False


def is_valid_floor_label(text: str) -> bool:
    """Checks if text matches the floor label format."""
    text = text.strip()
    if 'FLOOR' in text.upper():
        return True
    if text.startswith(('+', '-', '±')):
        return True
    return False


def convert_persian_numerals(text: str) -> str:
    """Converts Persian/Arabic numerals in a string to Western Arabic numerals."""
    persian_to_english = str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')
    return text.translate(persian_to_english)


def extract_axis_labels(root: ET.Element, scale_factor: float) -> List[Dict]:
    """
    Extracts axis labels by finding a seed label and then searching for other
    labels at a fixed real-world distance (780cm), converted to pixels.
    """
    EXPECTED_AXIS_DISTANCE_CM = 780.0
    DISTANCE_TOLERANCE_PX = 50.0

    if scale_factor == 0:
        return []
    pixel_distance = EXPECTED_AXIS_DISTANCE_CM / scale_factor

    all_candidates = []
    try:
        viewbox_height = float(root.get('viewBox', '0 0 0 1000').split()[3])
        Y_EDGE_THRESHOLD = viewbox_height * 0.20

        for elem in root.iterfind('.//{http://www.w3.org/2000/svg}text'):
            text_content = elem.text.strip() if elem.text else ''
            if is_valid_axis_label(text_content):
                try:
                    y_pos = float(elem.get('y', 0))
                    if y_pos < Y_EDGE_THRESHOLD or y_pos > (viewbox_height - Y_EDGE_THRESHOLD):
                        # --- FIX: Removed the unnecessary call to convert_persian_numerals ---
                        all_candidates.append({
                            'letter': text_content,
                            'x': float(elem.get('x', 0)),
                            'y': y_pos
                        })
                except (ValueError, TypeError):
                    continue
    except (ValueError, IndexError):
        return []

    if not all_candidates:
        return []

    y_coords = [round(p['y']) for p in all_candidates]
    if not y_coords:
        return []
    most_common_y = max(set(y_coords), key=y_coords.count)

    main_axis_candidates = [
        p for p in all_candidates if abs(p['y'] - most_common_y) < 20]
    if not main_axis_candidates:
        return []

    main_axis_candidates.sort(key=lambda p: p['x'])
    final_labels = []

    candidates_by_x = {round(p['x']): p for p in main_axis_candidates}
    found_labels = set()

    for seed_label in main_axis_candidates:
        if seed_label['letter'] in found_labels:
            continue

        current_chain = [seed_label]

        # Walk left
        current_x = seed_label['x']
        while True:
            found_next = False
            for x_coord, label in candidates_by_x.items():
                if abs((current_x - x_coord) - pixel_distance) < DISTANCE_TOLERANCE_PX:
                    current_chain.append(label)
                    current_x = x_coord
                    found_next = True
                    break
            if not found_next:
                break

        for label in current_chain:
            found_labels.add(label['letter'])

    final_labels = [
        p for p in main_axis_candidates if p['letter'] in found_labels]
    final_labels.sort(key=lambda a: a['x'])

    return final_labels


def extract_floor_labels(root: ET.Element) -> List[Dict]:
    """Extracts vertical floor level labels from the SVG."""
    labels = []
    for elem in root.iterfind('.//{http://www.w3.org/2000/svg}text'):
        try:
            text_content = elem.text.strip() if elem.text else ''
            y_str = elem.get('y')

            # --- FIX: Check that the 'y' attribute is not None ---
            if 'FLOOR' in text_content.upper() and y_str is not None:
                labels.append({'label': text_content, 'y': float(y_str)})
        except (ValueError, TypeError):
            continue

    labels.sort(key=lambda f: f['y'])
    return labels


def determine_axis_range(x: float, labels: List[Dict]) -> str:
    """Determines the axis span, correctly handling elements outside the main grid."""
    if not labels:
        return "N/A"

    if len(labels) == 1:
        return labels[0]['letter']

    # Check if the element is to the left of the entire grid
    if x < labels[0]['x']:
        return f"<{labels[0]['letter']}"

    # Check if the element is between two grid lines
    for i in range(len(labels) - 1):
        if labels[i]['x'] <= x <= labels[i+1]['x']:
            return f"{labels[i]['letter']}-{labels[i+1]['letter']}"

    # Check if the element is to the right of the entire grid
    if x > labels[-1]['x']:
        return f">{labels[-1]['letter']}"

    return "N/A"  # Fallback


def determine_floor(y: float, labels: List[Dict]) -> str:
    """Determines the floor level for a given Y coordinate."""
    for floor in labels:
        if y > floor['y']:  # Y is greater because origin (0,0) is top-left
            continue
        return floor['label']
    return labels[0]['label'] if labels else "N/A"


# --- 3. CORE PROCESSING FUNCTIONS ---

def clean_and_rebuild_svg(original_root, layers_to_keep: list):
    """
    Creates a new SVG, copying only specified layers and valid text elements.
    """
    ns = {'svg': 'http://www.w3.org/2000/svg'}
    new_root = ET.Element('svg', original_root.attrib)

    # 1. Copy all elements from the desired layers (including text inside them)
    for layer_name in layers_to_keep:
        layer_element = original_root.find(f".//svg:g[@id='{layer_name}']", ns)
        if layer_element is not None:
            new_root.append(layer_element)

    # 2. Find and copy only the valid "external" text elements
    for text_element in original_root.iterfind('.//svg:text', ns):
        text_content = text_element.text or ''
        # Check if the text is a valid axis or floor label
        if is_valid_axis_label(text_content) or is_valid_floor_label(text_content):
            # Check if it's already copied (because it was inside a kept layer)
            if new_root.find(f".//*[@x='{text_element.get('x')}'][@y='{text_element.get('y')}']") is None:
                new_root.append(text_element)

    print(f"  -> Rebuilt SVG with valid layers and text labels only.")
    return new_root


def get_rectangle_bounds(coords: list) -> dict:
    if not coords or len(coords) < 2:
        return {'width': 0, 'height': 0}
    x, y = [p[0] for p in coords], [p[1] for p in coords]
    return {'width': max(x) - min(x), 'height': max(y) - min(y)}


def classify_panel_type(bounds: dict, ratio_threshold: float = 1.5) -> str:
    if not bounds or 'width' not in bounds or 'height' not in bounds:
        return 'Unknown'
    if bounds['width'] > bounds['height'] * ratio_threshold:
        return 'Horizontal'
    if bounds['height'] > bounds['width'] * ratio_threshold:
        return 'Vertical'
    return 'Square/Other'


def inject_panel_ids(layer_element, existing_elements_map: dict):
    """
    MODE 'CREATE_PROCESSED_SVGS': Injects existing IDs from the database
    by matching the panel's geometry.
    """
    paths = [child for child in layer_element if child.tag.endswith('path')]
    if not paths:
        return

    found_count = 0
    not_found_count = 0

    for path_element in paths:
        coords = parse_path_coordinates(path_element.get('d'))
        if not coords:
            continue

        # Create the geometry key to look up in our map
        geometry_key = json.dumps(coords)

        element_id = existing_elements_map.get(geometry_key)

        if element_id:
            path_element.set('id', element_id)
            found_count += 1
        else:
            not_found_count += 1

    print(
        f"    -> Injected {found_count} IDs in layer '{layer_element.attrib.get('id')}'.")
    if not_found_count > 0:
        print(
            f"    -> WARNING: {not_found_count} panels in this layer were not found in the database.")


def process_layer_for_database(root: ET.Element, layer_name: str, id_prefix: str, filename_prefix: str, svg_filename: str, svg_context: dict, axis_labels: list, floor_labels: list, scale_factor: float) -> List[Dict]:
    """
    Extracts all data, now including the scale factor and real-world coordinates.
    """
    layer_element = root.find(
        f".//{{http://www.w3.org/2000/svg}}g[@id='{layer_name}']")
    if layer_element is None:
        return []

    paths = [child for child in layer_element if child.tag.endswith('path')]
    results = []

    # Create a list of panels with their sorting coordinates and data
    panels_to_sort = []
    for path_element in paths:
        path_d = path_element.get('d')
        if path_d:
            coords = parse_path_coordinates(path_d)
            if coords:
                sort_y = min(p[1] for p in coords)
                sort_x = max(p[0] for p in coords)
                panels_to_sort.append({
                    'd': path_d, 'coords': coords, 'y': sort_y, 'x': sort_x
                })

    # Sort top-to-bottom, then right-to-left
    panels_to_sort.sort(key=lambda p: (p['y'], -p['x']))

    block_code = svg_context.get('block', 'X')
    contractor_initials = get_contractor_initials(
        svg_context.get('contractor', 'Unknown'))

    # Process sorted panels to generate final data
    for i, panel_data in enumerate(panels_to_sort):
        panel_counter = i + 1
        new_element_id = f"{filename_prefix}-{id_prefix}-{panel_counter}-{block_code}-{contractor_initials}"

        coords = panel_data['coords']
        center = get_rectangle_center(coords)
        center_px = get_rectangle_center(coords)
        bounds_px = get_rectangle_bounds(coords)
        orientation = classify_panel_type(bounds_px)
        axis_span = determine_axis_range(center[0], axis_labels)
        floor_level = determine_floor(center[1], floor_labels)

        real_x_cm = center_px[0] * scale_factor
        real_y_cm = center_px[1] * scale_factor
        width_cm = bounds_px['width'] * scale_factor
        height_cm = bounds_px['height'] * scale_factor
        area_sqm = (width_cm / 100) * (height_cm / 100)

        results.append({
            'element_id': new_element_id,
            'element_type': layer_name,
            'zone_name': filename_prefix,
            'svg_file_name': svg_filename,
            'axis_span': axis_span,
            'floor_level': floor_level,
            'contractor': svg_context.get('contractor'),
            'block': svg_context.get('block'),
            'plan_file': svg_filename,
            # Store all coordinates as a JSON string
            'geometry_json': json.dumps(coords),
            'panel_orientation': orientation,
            'width_cm': round(width_cm, 2),
            'height_cm': round(height_cm, 2),
            'area_sqm': round(area_sqm, 2),
            'scale_factor': scale_factor,          # Added
            'real_x_cm': round(real_x_cm, 2),    # Added
            'real_y_cm': round(real_y_cm, 2)     # Added
        })

    print(
        f"    -> Extracted {len(results)} panels from layer '{layer_name}' with real-world coordinate data.")
    return results


def get_line_length(line_element: ET.Element) -> float:
    """Calculates the pixel length of an SVG <line> element safely."""
    try:
        x1_str = line_element.get('x1')
        y1_str = line_element.get('y1')
        x2_str = line_element.get('x2')
        y2_str = line_element.get('y2')

        if x1_str is not None and y1_str is not None and x2_str is not None and y2_str is not None:
            x1 = float(x1_str)
            y1 = float(y1_str)
            x2 = float(x2_str)
            y2 = float(y2_str)
            # This path correctly returns a float
            return ((x2 - x1)**2 + (y2 - y1)**2)**0.5

    except (ValueError, TypeError):
        # This path correctly returns a float
        return 0.0

    # --- THIS IS THE FIX ---
    # This path handles the case where attributes are missing (None)
    # and ensures the function always returns a float as declared.
    return 0.0


def calculate_scale_factor(root: ET.Element) -> float:
    """
    Calculates the scale factor based on a fixed rule: finds the single path
    inside the <g id="Default"> layer and assumes its length is 100cm.
    """
    REFERENCE_LENGTH_CM = 100.0
    ns = {'svg': 'http://www.w3.org/2000/svg'}

    default_layer = root.find(f".//svg:g[@id='Default']", ns)
    if default_layer is None:
        print(
            "  -> WARNING: Could not find the <g id='Default'> layer for scale calculation.")
        return 1.0

    reference_path = default_layer.find('svg:path', ns)
    if reference_path is None:
        print("  -> WARNING: Found 'Default' layer, but no <path> inside it for scale calculation.")
        return 1.0

    # --- THIS IS THE FIX ---
    # 1. Get the path data first.
    path_d = reference_path.get('d')

    # 2. Check if the 'd' attribute actually exists before using it.
    if not path_d:
        print("  -> WARNING: Found reference <path>, but it's missing the 'd' attribute (coordinate data).")
        return 1.0
    # --- END OF FIX ---

    # Now we can safely process the path data
    pixel_length = get_simple_path_length(path_d)

    if pixel_length > 0:
        scale_factor = REFERENCE_LENGTH_CM / pixel_length
        print(
            f"  -> Scale factor calculated successfully: {scale_factor:.4f} cm/pixel.")
        return scale_factor
    else:
        print(
            f"  -> WARNING: Found reference path, but could not measure its length. Path data: {path_d}")
        return 1.0


def fetch_elements_map(plan_filename: str) -> dict:
    """
    Connects to the DB and fetches all elements for a given plan file.
    Returns a map where the key is the geometry and the value is the element record.
    """
    data_map = {}
    connection = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor(dictionary=True)
        print(
            f"\nFetching existing elements for {plan_filename} from database...")

        query = "SELECT element_id, geometry_json FROM elements WHERE plan_file = %s"
        cursor.execute(query, (plan_filename,))

        results = cursor.fetchall()
        for row in results:
            # --- THIS IS THE FIX ---
            # We 'cast' the row to a Dictionary type. This tells the linter
            # that we know it's a dictionary, resolving the warning.
            row_dict = cast(Dict, row)

            geometry = row_dict.get('geometry_json')
            element_id = row_dict.get('element_id')

            if geometry and element_id:
                data_map[geometry] = element_id

        print(f"  -> Found {len(results)} existing elements in the database.")
        return data_map

    except mysql.connector.Error as err:
        print(f"\n❌ Database Error while fetching elements: {err}")
        return {}
    finally:
        if connection and connection.is_connected():
            connection.close()


def insert_data_into_database(data_list: List[Dict]):
    """Connects to MySQL and inserts/updates data with full geometry."""
    if not data_list:
        print("\nNo data to insert.")
        return

    connection = None
    cursor = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()

        # Updated SQL query with the new geometry_json column
        query = ("INSERT INTO elements (element_id, element_type, zone_name, svg_file_name, axis_span, "
                 "floor_level, contractor, block, plan_file, geometry_json, panel_orientation, "
                 "width_cm, height_cm, area_sqm, scale_factor, real_x_cm, real_y_cm) "
                 "VALUES (%(element_id)s, %(element_type)s, %(zone_name)s, %(svg_file_name)s, %(axis_span)s, "
                 "%(floor_level)s, %(contractor)s, %(block)s, %(plan_file)s, %(geometry_json)s, %(panel_orientation)s, "
                 "%(width_cm)s, %(height_cm)s, %(area_sqm)s, %(scale_factor)s, %(real_x_cm)s, %(real_y_cm)s) "
                 "ON DUPLICATE KEY UPDATE "
                 "element_type=VALUES(element_type), zone_name=VALUES(zone_name), contractor=VALUES(contractor), "
                 "block=VALUES(block), axis_span=VALUES(axis_span), floor_level=VALUES(floor_level), "
                 "plan_file=VALUES(plan_file), geometry_json=VALUES(geometry_json), panel_orientation=VALUES(panel_orientation), "
                 "width_cm=VALUES(width_cm), height_cm=VALUES(height_cm), area_sqm=VALUES(area_sqm), "
                 "scale_factor=VALUES(scale_factor), real_x_cm=VALUES(real_x_cm), real_y_cm=VALUES(real_y_cm)")

        cursor.executemany(query, data_list)
        connection.commit()
        print(
            f"✅ Success! Database operation completed for {cursor.rowcount} rows.")

    except mysql.connector.Error as err:
        print(f"\n❌ Database Error: {err}")
        if connection and connection.is_connected():
            connection.rollback()
    finally:
        if cursor:
            cursor.close()
        if connection and connection.is_connected():
            connection.close()

# --- 4. MAIN EXECUTION ---


def main():
    # =========================================================================
    # CHOOSE YOUR OPERATION MODE:
    # 'CREATE_PROCESSED_SVGS' : Cleans SVGs and injects IDs for the web app.
    # 'UPDATE_DATABASE'       : Extracts all panel data and saves it to MySQL.
    # =========================================================================
    OPERATION_MODE = 'CREATE_PROCESSED_SVGS'
    # =========================================================================

    print(f"--- Running in mode: {OPERATION_MODE} ---")
    try:
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            config_data = json.load(f)
        with open(GROUP_CONFIG_FILE, 'r', encoding='utf-8') as f:
            group_config_data = json.load(f)
        print("Configuration files loaded.")
    except Exception as e:
        print(f"CRITICAL ERROR: Could not load JSON config files. {e}")
        return

    os.makedirs(OUTPUT_FOLDER, exist_ok=True)
    svg_files_to_process = sorted(list({
        zone['svgFile'] for zones in config_data.values() if isinstance(zones, list) for zone in zones
    }))

    db_data_accumulator = []

    for svg_filename in svg_files_to_process:
        input_path = os.path.join(INPUT_FOLDER, svg_filename)
        if not os.path.exists(input_path):
            print(f"\n--- WARNING: File not found, skipping: {input_path} ---")
            continue

        print(f"\n--- Processing: {svg_filename} ---")
        try:
            ET.register_namespace('', "http://www.w3.org/2000/svg")
            tree = ET.parse(input_path)
            root = tree.getroot()
        except Exception as e:
            print(f"  ERROR parsing SVG: {e}")
            continue

        svg_context = get_svg_context(
            svg_filename, config_data, group_config_data)
        filename_prefix = get_short_filename_prefix(input_path)

        # --- MODE LOGIC ---
        if OPERATION_MODE == 'CREATE_PROCESSED_SVGS':
            # --- NEW WORKFLOW ---
            # 1. Fetch the official data for this plan from the database
            existing_elements_map = fetch_elements_map(svg_filename)
            if not existing_elements_map:
                print(
                    f"  -> Skipping ID injection for {svg_filename} as no data was found in the database.")
                continue

            # 2. Clean the SVG
            cleaned_root = clean_and_rebuild_svg(
                root, list(LAYERS_TO_PROCESS.keys()))

            # 3. Inject IDs by looking them up in the data we fetched
            for layer_name in LAYERS_TO_PROCESS.keys():
                layer_element = cleaned_root.find(
                    f".//{{http://www.w3.org/2000/svg}}g[@id='{layer_name}']")
                if layer_element is not None:
                    inject_panel_ids(layer_element, existing_elements_map)

            new_tree = ET.ElementTree(cleaned_root)
            output_path = os.path.join(OUTPUT_FOLDER, svg_filename)
            new_tree.write(output_path, encoding='utf-8', xml_declaration=True)
            print(
                f"  -> Saved cleaned SVG with database-synced IDs to: {output_path}")

        elif OPERATION_MODE == 'UPDATE_DATABASE':
            # This workflow uses the original, untouched root element

            # 1. Calculate scale factor FIRST.
            scale_factor = calculate_scale_factor(root)

            # 2. Pass the scale factor to the label extraction function.
            axis_labels = extract_axis_labels(root, scale_factor)
            floor_labels = extract_floor_labels(root)
            print(
                f"  -> Found {len(axis_labels)} valid axis and {len(floor_labels)} valid floor labels.")

            # 3. Process each layer using the calculated context
            for layer_name, id_prefix in LAYERS_TO_PROCESS.items():
                if id_prefix is None:
                    continue  # Skip layers like 'Default'

                layer_data = process_layer_for_database(
                    root, layer_name, id_prefix, filename_prefix, svg_filename,
                    svg_context, axis_labels, floor_labels, scale_factor
                )
                db_data_accumulator.extend(layer_data)

    # After looping through all files, if in database mode, insert all collected data
    if OPERATION_MODE == 'UPDATE_DATABASE' and db_data_accumulator:
        insert_data_into_database(db_data_accumulator)

    print("\n\nProcessing complete.")


if __name__ == "__main__":
    # You would have all your function definitions here from previous steps

    main()
