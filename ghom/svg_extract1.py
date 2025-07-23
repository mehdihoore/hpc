import xml.etree.ElementTree as ET
import re
import os
import glob
import hashlib
import json
import mysql.connector
import _mysql_connector
from typing import List, Tuple, Dict
from pathlib import Path

# ===================================================================================
#
#              FINAL, ALL-IN-ONE PYTHON DATA PROCESSOR
#
# This script is the complete and final version. Its single purpose is to:
# - Read all raw SVG files listed in your JSON configuration.
# - Extract all panel data (GFRC and GLASS).
# - Determine Contractor, Block, Floor Level, and Axis Span.
# - Generate a permanent, unique ID for every panel.
# - Save all complete data to the MySQL database.
#
# ===================================================================================

# --- DATABASE CONFIGURATION ---
DB_CONFIG = {
    'host': 'localhost',
    'database': 'hpc_ghom',
    'user': 'root',
    'password': ''
}

# --- SECTION 1: HELPER FUNCTIONS ---


def get_svg_context(svg_filename: str, config_data: dict, group_config_data: dict) -> dict:
    """Finds contractor, block, and floor level for a given SVG file."""
    for region_key, zones in config_data.items():
        if isinstance(zones, list):
            for zone in zones:
                if zone.get('svgFile') == svg_filename:
                    region_details = group_config_data.get(region_key, {})
                    return {
                        'contractor': region_details.get('contractor', 'N/A'),
                        'block': region_details.get('block', 'N/A'),
                        'floorLevel': zone.get('floorLevel', 'N/A')
                    }
    return {'contractor': 'Unknown', 'block': 'Unknown', 'floorLevel': 'Unknown'}


def get_contractor_initials(contractor_name: str) -> str:
    """Creates two-letter initials from a contractor's name."""
    if not contractor_name:
        return 'XX'
    words = contractor_name.split()
    if len(words) > 1:
        # Takes the first letter of the first two words
        return (words[0][0] if words[0] else '') + (words[1][0] if words[1] else '')
    elif words:
        # Takes the first two letters of a single word
        return words[0][:2]
    return 'XX'


def parse_path_coordinates(path_d: str) -> List[Tuple[float, float]]:
    try:
        path_d = path_d.strip().replace('z', '').replace('Z', '')
        coord_pattern = r'([ML])\s*([\d.-]+)\s*,\s*([\d.-]+)'
        matches = re.findall(coord_pattern, path_d)
        return [(float(x), float(y)) for _, x, y in matches]
    except Exception:
        return []


def get_rectangle_center(c: List[Tuple[float, float]]) -> Tuple[float, float]:
    if not c:
        return (0, 0)
    x, y = [p[0] for p in c], [p[1] for p in c]
    return ((min(x) + max(x)) / 2, (min(y) + max(y)) / 2)


def get_rectangle_bounds(c: List[Tuple[float, float]]) -> Dict[str, float]:
    if not c:
        return {}
    x, y = [p[0] for p in c], [p[1] for p in c]
    return {'width': max(x) - min(x), 'height': max(y) - min(y)}


def classify_panel_type(b: Dict[str, float], r: float = 1.5) -> str:
    if b['width'] > b['height'] * r:
        return 'Horizontal'
    if b['height'] > b['width'] * r:
        return 'Vertical'
    return 'Square/Other'


def get_short_filename_prefix(path: str) -> str:
    name = Path(path).stem.replace('Zone', 'Z')
    m = re.search(r'Z?(\d+)', name)
    return f"Z{m.group(1)}" if m else name[:3].upper()


def find_layers_to_process(root) -> List[Tuple[ET.Element, str]]:
    layers, processed = [], set()
    for layer_name in ['GFRC', 'GLASS']:
        elem = root.find(
            f".//{{http://www.w3.org/2000/svg}}g[@id='{layer_name}']")
        if elem is not None and layer_name not in processed:
            layers.append((elem, layer_name))
            processed.add(layer_name)
    return layers


def get_layer_colors(layer_name: str) -> Dict[str, str]:
    """Returns a color scheme for a given layer name."""
    layer_color_schemes = {
        'GFRC': {'Horizontal': "#4A90E2", 'Vertical': "#7ED321", 'Square/Other': "#F5A623"},
        'GLASS': {'Horizontal': "#50E3C2", 'Vertical': "#B8E986", 'Square/Other': "#F8E71C"},
    }
    default_colors = {'Horizontal': "#D3D3D3",
                      'Vertical': "#A9A9A9", 'Square/Other': "#696969"}
    return layer_color_schemes.get(layer_name.upper(), default_colors)


def add_circle_with_text(parent, element_id: str, display_label: str, x: float, y: float, panel_type: str):
    """Adds a colored circle with a label to the SVG."""
    ns = parent.tag.split('}')[0] + '}' if parent.tag.startswith('{') else ''
    color_map = {'Horizontal': '#4A90E2',
                 'Vertical': '#7ED321', 'Square/Other': '#F5A623'}
    fill_color = color_map.get(panel_type, '#696969')

    g = ET.SubElement(parent, f'{ns}g')
    g.set('id', element_id)
    g.set('class', 'interactive-panel')

    circle = ET.SubElement(g, f'{ns}circle')
    circle.set('cx', str(x))
    circle.set('cy', str(y))
    circle.set('r', '14')
    circle.set('fill', fill_color)
    circle.set('stroke', 'white')
    circle.set('stroke-width', '2')

    text_elem = ET.SubElement(g, f'{ns}text')
    text_elem.set('x', str(x))
    text_elem.set('y', str(y + 2))
    text_elem.set('font-size', '6')
    text_elem.set('font-family', 'Arial, sans-serif')
    text_elem.set('font-weight', 'bold')
    text_elem.set('fill', 'white')
    text_elem.set('text-anchor', 'middle')
    text_elem.set('dominant-baseline', 'middle')
    text_elem.set('pointer-events', 'none')
    text_elem.text = display_label


def should_include_panel(rect_info: dict, layer_name: str) -> bool:
    w, h, a = rect_info['bounds']['width'], rect_info['bounds']['height'], rect_info['bounds']['width'] * \
        rect_info['bounds']['height']
    if 'glass' in layer_name.lower():
        return w >= 5 and h >= 5 and a >= 100
    return w >= 20 and h >= 20 and a >= 1000


def process_and_inject_ids(layer_element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict) -> List[Dict]:
    paths = [child for child in layer_element if child.tag.endswith('path')]
    if not paths:
        return []

    results = []
    for i, path_element in enumerate(paths):
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            center = get_rectangle_center(coords)
            panel_type = classify_panel_type(get_rectangle_bounds(coords))

            # 1. Generate the permanent hash ID
            signature = f"{base_svg_filename}-{layer_name}-{path_d}-{i}".encode()
            unique_id = hashlib.md5(signature).hexdigest()

            # 2. Inject the ID into the SVG element itself
            path_element.set('id', unique_id)

            # 3. Prepare the data for the database
            results.append({
                'element_id': unique_id, 'element_type': layer_name,
                # Point to the new file
                'zone_name': filename_prefix, 'svg_file_name': f"{base_svg_filename}_processed.svg",
                'axis_span': 'N/A', 'floor_level': svg_context.get('floorLevel'),
                'contractor': svg_context.get('contractor'), 'block': svg_context.get('block'),
                'plan_file': f"{base_svg_filename}_processed.svg",
                'x_coord': center[0], 'y_coord': center[1],
                'panel_orientation': panel_type
            })
    print(
        f"    -> Processed and injected IDs for {len(results)} panels in layer '{layer_name}'.")
    return results


def extract_axis_labels(root) -> List[Dict]:
    """
    IMPROVED AND CORRECTED: Smarter logic to find axis labels.
    Fixes the 'unbound variable' warning by providing a default height.
    """
    labels = []

    # --- FIX: Initialize viewbox_height with a default value ---
    viewbox_height = 1000.0  # Default fallback height
    Y_EDGE_THRESHOLD = 200  # Default fallback threshold

    try:
        # Try to get the real height from the SVG's viewBox attribute
        viewbox_str = root.get('viewBox')
        if viewbox_str:
            viewbox_parts = [float(v) for v in viewbox_str.split()]
            if len(viewbox_parts) == 4:
                _, _, _, viewbox_height = viewbox_parts

        # Calculate the threshold based on the actual height
        Y_EDGE_THRESHOLD = viewbox_height * 0.15

    except (ValueError, TypeError):
        # If parsing fails for any reason, the default values from above will be used
        print("  - Warning: Could not parse viewBox attribute. Using default thresholds for axis detection.")

    for elem in root.iterfind('.//{http://www.w3.org/2000/svg}text'):
        try:
            # Check for short, simple text content
            if elem.text and (txt := elem.text.strip()) and 0 < len(txt) < 4:
                y_pos = float(elem.get('y'))

                # Check if the text is near the top or bottom edge using the threshold
                if y_pos < Y_EDGE_THRESHOLD or y_pos > (viewbox_height - Y_EDGE_THRESHOLD):
                    labels.append({'letter': txt, 'x': float(elem.get('x'))})
        except (ValueError, TypeError):
            # Ignore text elements with invalid coordinates
            continue

    labels.sort(key=lambda a: a['x'])
    return labels

# REPLACE your old extract_floor_labels function with this one


def extract_floor_labels(root) -> List[Dict]:
    """
    FINAL, INTELLIGENT VERSION: Finds both floor names and elevation markers,
    pairs them together, and returns a clean list of floor boundaries.
    """
    potential_labels = []
    # Find any text that looks like a floor label (e.g., "5th FLOOR", "+26.65", "GROUND FLOOR")
    for elem in root.iterfind('.//{http://www.w3.org/2000/svg}text'):
        try:
            if elem.text and (txt := elem.text.strip()):
                if 'FLOOR' in txt.upper() or re.match(r'^[±+]', txt):
                    potential_labels.append(
                        {'label': txt, 'y': float(elem.get('y'))})
        except (ValueError, TypeError):
            continue

    if not potential_labels:
        return []

    # Separate the labels into names (e.g., "5th FLOOR") and levels (e.g., "+26.65")
    name_labels = [
        p for p in potential_labels if 'FLOOR' in p['label'].upper()]
    level_labels = [
        p for p in potential_labels if 'FLOOR' not in p['label'].upper()]

    clean_labels = []
    # Intelligently pair each level marker with its closest name
    for level in level_labels:
        # Find the name label with the smallest y-distance from this level marker
        closest_name = min(name_labels, key=lambda name: abs(
            name['y'] - level['y']), default=None)

        if closest_name:
            # Create a clean record using the NAME, but the Y-COORDINATE of the level marker
            clean_labels.append({
                'label': closest_name['label'],
                'y': level['y']
            })

    # Sort the final, clean labels by their y-position
    clean_labels.sort(key=lambda f: f['y'])
    return clean_labels


def determine_axis_range(x: float, labels: List[Dict]) -> str:
    if len(labels) < 2:
        return "N/A"
    for i in range(len(labels) - 1):
        if labels[i]['x'] <= x <= labels[i+1]['x']:
            return f"{labels[i]['letter']}-{labels[i+1]['letter']}"
    if x < labels[0]['x']:
        return f"<{labels[0]['letter']}"
    if x > labels[-1]['x']:
        return f">{labels[-1]['letter']}"
    return "N/A"


def determine_floor(y: float, labels: List[Dict]) -> str:
    """
    IMPROVED VERSION: Correctly determines the floor level for an element.
    It finds the first floor line that is BELOW the element's center.
    """
    if not labels:
        return "N/A"

    # Find the first floor line whose Y-position is greater than the element's Y-position
    for floor in labels:
        if y < floor['y']:
            # The element is above this floor line, so it belongs to this floor
            return floor['label']

    # If the element is below all found floor lines, assign it to the last one
    return labels[-1]['label'] if labels else "N/A"
# --- SECTION 2: CORE PROCESSING AND DATABASE LOGIC ---


def process_elements(layer_element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict, axis_labels: List[Dict], floor_labels: List[Dict]) -> List[Dict]:
    """
    MODIFIED: Now filters panels to exclude those from other views.
    """
    paths = [child for child in layer_element if child.tag.endswith('path')]
    if not paths:
        print(
            f"    -> NOTE: Layer '{layer_name}' contains 0 panel elements (paths).")
        return []

    # First, gather all potential rectangles with their info
    all_rectangles = []
    for path_element in paths:
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            all_rectangles.append({
                'element': path_element,
                'bounds': get_rectangle_bounds(coords),
                'center': get_rectangle_center(coords),
                'original_d': path_d
            })

    # --- NEW FILTERING STEP ---
    # Apply the filter to remove panels that are too small (from other views)
    filtered_rectangles = [
        rect for rect in all_rectangles if should_include_panel(rect, layer_name)
    ]
    print(
        f"    -> Found {len(all_rectangles)} total panels, keeping {len(filtered_rectangles)} after filtering.")
    # --- END OF FILTERING STEP ---

    results = []
    # Now, process only the filtered rectangles
    for i, rect in enumerate(filtered_rectangles):
        center = rect['center']

        # All data is determined here
        axis_span = determine_axis_range(center[0], axis_labels)
        floor_level = determine_floor(center[1], floor_labels)
        panel_type = classify_panel_type(rect['bounds'])
        signature = f"{base_svg_filename}-{layer_name}-{rect['original_d']}-{i}".encode(
        )
        unique_id = hashlib.md5(signature).hexdigest()

        results.append({
            'element_id': unique_id, 'element_type': layer_name,
            'zone_name': filename_prefix, 'svg_file_name': f"{base_svg_filename}.svg",
            'axis_span': axis_span,
            'floor_level': floor_level,
            'contractor': svg_context.get('contractor'),
            'block': svg_context.get('block'),
            'plan_file': f"{base_svg_filename}.svg",
            'x_coord': center[0], 'y_coord': center[1],
            'panel_orientation': panel_type
        })

    print(f"    -> Extracted {len(results)} panels from layer '{layer_name}'.")
    return results


def insert_data_into_database(data_list: List[Dict]):
    if not data_list:
        print("\nNo data to insert.")
        return
    connection = None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()
        print(f"\nConnecting to '{DB_CONFIG['database']}' to insert data...")
        query = ("INSERT INTO elements (element_id, element_type, zone_name, svg_file_name, axis_span, "
                 "floor_level, contractor, block, plan_file, x_coord, y_coord, panel_orientation) VALUES (%(element_id)s, "
                 "%(element_type)s, %(zone_name)s, %(svg_file_name)s, %(axis_span)s, %(floor_level)s, %(contractor)s, "
                 "%(block)s, %(plan_file)s, %(x_coord)s, %(y_coord)s, %(panel_orientation)s) ON DUPLICATE KEY UPDATE "
                 "element_type=VALUES(element_type), zone_name=VALUES(zone_name), contractor=VALUES(contractor), block=VALUES(block), "
                 "axis_span=VALUES(axis_span), floor_level=VALUES(floor_level)")

        chunk_size, total = 500, len(data_list)
        print(f"Preparing to insert/update {total} rows...")
        for i in range(0, total, chunk_size):
            cursor.executemany(query, data_list[i:i + chunk_size])
            print(
                f"  ...submitted chunk {i//chunk_size + 1} of {total//chunk_size + 1}")
        connection.commit()
        print(f"\n✅ Success! Database operation completed for {total} items.")
    except Exception as err:
        print(f"\n❌ Database Error: {err}")
        if connection:
            connection.rollback()
    finally:
        if connection and connection.is_connected():
            connection.close()
            print("Database connection closed.")

# --- SECTION 3: MAIN EXECUTION ---


def process_elements_for_database(layer_element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict, axis_labels: List[Dict], floor_labels: List[Dict]) -> List[Dict]:
    """
    MODE 1: Extracts data for the database.
    FINAL VERSION: Now includes the should_include_panel filter.
    """
    paths = [child for child in layer_element if child.tag.endswith('path')]
    if not paths:
        return []

    # Step 1: Gather all potential panels first
    all_rectangles = []
    for path_element in paths:
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            all_rectangles.append({
                'bounds': get_rectangle_bounds(coords),
                'center': get_rectangle_center(coords),
            })

    # Step 2: Apply the filter to get only the panels we want
    filtered_rectangles = [
        rect for rect in all_rectangles if should_include_panel(rect, layer_name)]
    print(
        f"    -> Found {len(all_rectangles)} total panels, keeping {len(filtered_rectangles)} for database after filtering.")

    results = []
    block_code = svg_context.get('block', 'X')
    contractor_initials = get_contractor_initials(
        svg_context.get('contractor', 'Unknown'))
    element_type_code = 'GC' if 'GFRC' in layer_name.upper() else 'GL'

    # Step 3: Loop over the FILTERED list to generate IDs and data
    for i, rect in enumerate(filtered_rectangles):
        panel_counter = i + 1
        center = rect['center']

        new_element_id = f"{filename_prefix}-{element_type_code}-{panel_counter}-{block_code}-{contractor_initials}"

        results.append({
            'element_id': new_element_id,
            'element_type': layer_name,
            'zone_name': filename_prefix,
            'svg_file_name': f"{base_svg_filename}.svg",
            'axis_span': determine_axis_range(center[0], axis_labels),
            'floor_level': determine_floor(center[1], floor_labels),
            'contractor': svg_context.get('contractor'),
            'block': block_code,
            'plan_file': f"{base_svg_filename}.svg",
            'x_coord': center[0],
            'y_coord': center[1],
            'panel_orientation': classify_panel_type(rect['bounds'])
        })
    return results


def process_layer(layer_element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict, axis_labels: List[Dict], floor_labels: List[Dict]) -> List[Dict]:
    """
    This single function finds all panels, filters them, and generates
    a complete record for each one, including a unique ID and all contextual data.
    It returns a list of dictionaries, ready for either database insertion or SVG creation.
    """
    paths = [child for child in layer_element if child.tag.endswith('path')]

    all_rectangles = []
    for path_element in paths:
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            all_rectangles.append({
                'element': path_element,
                'bounds': get_rectangle_bounds(coords),
                'center': get_rectangle_center(coords),
                'original_d': path_d
            })

    # Apply the filter to remove unwanted panels
    filtered_rectangles = [
        rect for rect in all_rectangles if should_include_panel(rect, layer_name)]
    print(
        f"    -> Found {len(all_rectangles)} panels, processing {len(filtered_rectangles)} after filtering.")

    processed_elements = []
    block_code = svg_context.get('block', 'X')
    contractor_initials = get_contractor_initials(
        svg_context.get('contractor', 'Unknown'))
    element_type_code = 'GC' if 'GFRC' in layer_name.upper() else 'GL'

    for i, rect in enumerate(filtered_rectangles):
        panel_counter = i + 1
        center = rect['center']

        # Generate the single, official, human-readable ID
        element_id = f"{filename_prefix}-{element_type_code}-{panel_counter}-{block_code}-{contractor_initials}"

        processed_elements.append({
            'svg_element': rect['element'],
            'db_record': {
                'element_id': element_id,
                'element_type': layer_name,
                'zone_name': filename_prefix,
                'svg_file_name': f"{base_svg_filename}_processed.svg",
                'axis_span': determine_axis_range(center[0], axis_labels),
                'floor_level': determine_floor(center[1], floor_labels),
                'contractor': svg_context.get('contractor'),
                'block': block_code,
                'plan_file': f"{base_svg_filename}_processed.svg",
                'x_coord': center[0],
                'y_coord': center[1],
                'panel_orientation': classify_panel_type(rect['bounds'])
            }
        })
    return processed_elements


def process_elements_for_svg_creation(layer_element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict, axis_labels: List[Dict], floor_labels: List[Dict]):
    """
    MODE 2: Visually modifies an SVG file.
    FINAL VERSION: Now includes the should_include_panel filter and sets element IDs.
    """
    paths = [child for child in layer_element if child.tag.endswith('path')]
    if not paths:
        return

    # Step 1: Gather all potential panels first
    all_rectangles = []
    for path_element in paths:
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            all_rectangles.append({
                'element': path_element,  # Keep track of the SVG element
                'bounds': get_rectangle_bounds(coords),
                'center': get_rectangle_center(coords),
                'original_d': path_d
            })

    # Step 2: Apply the filter to get only the panels we want
    filtered_rectangles = [
        rect for rect in all_rectangles if should_include_panel(rect, layer_name)]
    print(
        f"    -> Found {len(all_rectangles)} total panels, visually processing {len(filtered_rectangles)} after filtering.")

    block_code = svg_context.get('block', 'X')
    contractor_initials = get_contractor_initials(
        svg_context.get('contractor', 'Unknown'))
    element_type_code = 'GC' if 'GFRC' in layer_name.upper() else 'GL'
    layer_colors = get_layer_colors(layer_name)

    # Step 3: Loop over the FILTERED list to modify the SVG
    for i, rect in enumerate(filtered_rectangles):
        panel_counter = i + 1
        center = rect['center']
        panel_type = classify_panel_type(rect['bounds'])
        path_element = rect['element']

        new_element_id = f"{filename_prefix}-{element_type_code}-{panel_counter}-{block_code}-{contractor_initials}"

        # *** FIX: Set the ID attribute on the path element itself ***
        path_element.set('id', new_element_id)

        panel_fill_color = layer_colors.get(panel_type, '#808080')
        path_element.set('fill', panel_fill_color)
        path_element.set('fill-opacity', "0.7")
        path_element.set('stroke', 'black')
        path_element.set('stroke-width', '1.5')

        # Use the same ID for both the circle and the text
        add_circle_with_text(layer_element, f"{new_element_id}_circle",
                             new_element_id, center[0], center[1], panel_type)

# --- SECTION 4: MAIN EXECUTION ---


def main():
    # =========================================================================
    # --- SCRIPT CONFIGURATION ---
    #
    # Choose which operation you want to perform by setting the mode below.
    #
    # 'UPDATE_DATABASE':  Populates your MySQL table with all data,
    #                     including contractor, block, floor, and axis.
    #
    # 'CREATE_PROCESSED_SVGS': Creates new, visually modified SVG files
    #                          with colored panels and clickable labels.
    #
    OPERATION_MODE = 'UPDATE_DATABASE'
    # =========================================================================

    print(f"--- Running in mode: {OPERATION_MODE} ---")

    try:
        with open('regionToZoneMap.json', 'r', encoding='utf-8') as f:
            config_data = json.load(f)
        with open('svgGroupConfig.json', 'r', encoding='utf-8') as f:
            group_config_data = json.load(f)
        print("Configuration files loaded successfully.")
    except Exception as e:
        print(f"CRITICAL ERROR: Could not load JSON config files. {e}")
        return

    input_folder = r"C:\xampp\htdocs\public_html\ghom\svg"
    output_folder = r"C:\xampp\htdocs\public_html\ghom\processed"
    os.makedirs(output_folder, exist_ok=True)

    # Get a unique list of all SVG files to process from the config
    svg_files_to_process = sorted(list({
        zone['svgFile'] for zones in config_data.values()
        if isinstance(zones, list) for zone in zones
    }))

    all_panel_data = []

    for svg_filename in svg_files_to_process:
        svg_file_path = os.path.join(input_folder, svg_filename)
        if not os.path.exists(svg_file_path):
            print(
                f"\n--- WARNING: File not found, skipping: {svg_file_path} ---")
            continue

        print(f"\n--- Processing: {svg_filename} ---")
        base_name = Path(svg_file_path).stem
        filename_prefix = get_short_filename_prefix(svg_file_path)

        try:
            ET.register_namespace('', "http://www.w3.org/2000/svg")
            tree = ET.parse(svg_file_path)
            root = tree.getroot()
        except Exception as e:
            print(f"  ERROR parsing SVG: {e}")
            continue

        # --- Gather all necessary context for this file ---
        svg_context = get_svg_context(
            svg_filename, config_data, group_config_data)
        axis_labels = extract_axis_labels(root)
        floor_labels = extract_floor_labels(root)
        print(
            f"  Context: Floor={svg_context.get('floorLevel')}, Contractor={svg_context.get('contractor')}, Block={svg_context.get('block')}")
        print(
            f"  Found {len(axis_labels)} axis markers and {len(floor_labels)} floor markers.")

        layers = find_layers_to_process(root)
        if not layers:
            print("  !! No processable layers ('GFRC', 'GLASS') were found.")
            continue

        # --- Execute the chosen operation ---
        for layer_element, layer_name in layers:
            if OPERATION_MODE == 'UPDATE_DATABASE':
                results = process_elements_for_database(
                    layer_element, layer_name, filename_prefix, base_name,
                    svg_context, axis_labels, floor_labels
                )
                all_panel_data.extend(results)

            elif OPERATION_MODE == 'CREATE_PROCESSED_SVGS':
                # FIX: Add the missing axis_labels and floor_labels arguments
                process_elements_for_svg_creation(
                    layer_element, layer_name, filename_prefix, base_name,
                    svg_context, axis_labels, floor_labels
                )

        # If in SVG creation mode, save the file after all its layers are processed
        if OPERATION_MODE == 'CREATE_PROCESSED_SVGS':
            output_svg_path = os.path.join(
                output_folder, f"{base_name}.svg")
            tree.write(output_svg_path, encoding='utf-8', xml_declaration=True)
            print(
                f"  -> Saved new visually modified SVG to: {output_svg_path}")

    # --- FINAL ACTION after all files are processed ---
    if OPERATION_MODE == 'UPDATE_DATABASE' and all_panel_data:
        insert_data_into_database(all_panel_data)
    elif not all_panel_data and OPERATION_MODE == 'UPDATE_DATABASE':
        print("\nCRITICAL: No panel data was extracted to update the database.")

    print("\nProcessing complete.")


if __name__ == "__main__":
    main()
