import xml.etree.ElementTree as ET
import re
import os
import glob
import hashlib
import mysql.connector
import _mysql_connector
from typing import List, Tuple, Dict
from pathlib import Path
import json
# --- DATABASE CONFIGURATION ---
DB_CONFIG = {
    'host': 'localhost',
    'database': 'hpc_ghom',
    'user': 'root',
    'password': ''
}


def get_svg_context(svg_filename: str, config_data: dict, group_config_data: dict) -> dict:
    """Finds the contractor and block for a given SVG file."""
    for region_key, zones in config_data.items():
        for zone in zones:
            if zone.get('svgFile') == svg_filename:
                # Found the file, now get the context from the group config
                region_details = group_config_data.get(region_key, {})
                return {
                    'contractor': region_details.get('contractor', 'N/A'),
                    'block': region_details.get('block', 'N/A')
                }
    return {'contractor': 'Unknown', 'block': 'Unknown'}


def parse_path_coordinates(path_d: str) -> List[Tuple[float, float]]:
    try:
        path_d = path_d.strip().replace('z', '').replace('Z', '')
        coord_pattern = r'([ML])\s*([\d.-]+)\s*,\s*([\d.-]+)'
        matches = re.findall(coord_pattern, path_d)
        return [(float(x), float(y)) for _, x, y in matches]
    except Exception:
        return []


def get_rectangle_center(coordinates: List[Tuple[float, float]]) -> Tuple[float, float]:
    if not coordinates:
        return (0, 0)
    x = [c[0] for c in coordinates]
    y = [c[1] for c in coordinates]
    return ((min(x) + max(x)) / 2, (min(y) + max(y)) / 2)


def get_rectangle_bounds(coordinates: List[Tuple[float, float]]) -> Dict[str, float]:
    if not coordinates:
        return {}
    x = [c[0] for c in coordinates]
    y = [c[1] for c in coordinates]
    return {'min_x': min(x), 'max_x': max(x), 'min_y': min(y), 'max_y': max(y), 'width': max(x)-min(x), 'height': max(y)-min(y)}


def classify_panel_type(bounds: Dict[str, float], r: float = 1.5) -> str:
    if bounds['width'] > bounds['height'] * r:
        return 'Horizontal'
    if bounds['height'] > bounds['width'] * r:
        return 'Vertical'
    return 'Square/Other'


def extract_axis_labels(root) -> List[Dict]:
    labels = []
    for elem in root.iter():
        if elem.tag.endswith('text') and elem.text and elem.text.strip():
            txt = elem.text.strip()
            if len(txt) == 1 or (txt.isdigit() and int(txt) <= 20):
                try:
                    x, y, fam, size = float(elem.get('x', 0)), float(elem.get('y', 0)), elem.get(
                        'font-family', ''), float(elem.get('font-size', 0))
                    if 'Romantic' in fam or size > 20 or y < 300:
                        labels.append({'letter': txt, 'x': x, 'y': y})
                except ValueError:
                    continue
    labels.sort(key=lambda a: a['x'])
    return labels


def extract_floor_labels(root) -> List[Dict]:
    labels = []
    for elem in root.iter():
        if elem.tag.endswith('text') and elem.text and 'FLOOR' in elem.text.upper():
            try:
                labels.append({'label': elem.text.strip(), 'x': float(
                    elem.get('x', 0)), 'y': float(elem.get('y', 0))})
            except ValueError:
                continue
    labels.sort(key=lambda f: f['y'])
    return labels


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
    if not labels:
        return "N/A"
    for i in range(len(labels) - 1):
        if labels[i]['y'] <= y <= labels[i+1]['y']:
            return labels[i+1]['label']
    if y < labels[0]['y']:
        return labels[0]['label']
    if y > labels[-1]['y']:
        return labels[-1]['label']
    return "N/A"


def should_include_panel(rect_info: dict, layer_name: str) -> bool:
    w, h, a = rect_info['bounds']['width'], rect_info['bounds']['height'], rect_info['bounds']['width'] * \
        rect_info['bounds']['height']
    if 'glass' in layer_name.lower():
        return w >= 5 and h >= 5 and a >= 100
    return w >= 20 and h >= 20 and a >= 1000


def get_layer_symbol(name: str) -> str:
    s = {'GFRC': 'G', 'GLASS': 'Gl', 'glass_40%': 'G4', 'glass_30%': 'G3',
         'glass_50%': 'G5', 'glass_opaque': 'Go', 'glass_80%': 'G8'}
    return s.get(name.upper(), name[:2].upper())


def get_short_filename_prefix(path: str) -> str:
    name = Path(path).stem.replace('Zone', 'Z').replace(
        '_t', '').replace('_modified', '')
    m = re.search(r'Z?(\d+)', name)
    return f"Z{m.group(1)}" if m else name[:3].upper()


def find_layers_to_process(root) -> List[Tuple[ET.Element, str]]:
    layers, processed = [], set()
    names = ['GFRC', 'GLASS', 'glass_40%', 'glass_30%', 'glass_50%', 'glass_opaque',
             'glass_80%', 'Glass', 'PANELS', 'WALL', 'STRUCTURE', 'ELEMENTS', 'FACADE']
    for elem in root.iter():
        if (elem_id := elem.get('id')) and elem_id not in processed and elem_id.upper() in [n.upper() for n in names]:
            print(f"Found layer: {elem_id}")
            layers.append((elem, elem_id))
            processed.add(elem_id)
    return layers

# --- NEW: MODIFIED CIRCLE FUNCTION ---


def add_circle_with_text(parent, element_id: str, display_label: str, x: float, y: float, panel_type: str):
    """
    MODIFIED: Adds a unique database ID and a CSS class to the circle element
    so it can be targeted by JavaScript.
    """
    ns = parent.tag.split('}')[0] + '}' if parent.tag.startswith('{') else ''

    color_map = {'Horizontal': '#4A90E2',
                 'Vertical': '#7ED321', 'Square/Other': '#F5A623'}
    fill_color = color_map.get(panel_type, '#696969')

    # Create a group for the circle and text to make them one clickable unit
    g = ET.SubElement(parent, f'{ns}g')
    g.set('id', element_id)  # The database ID is the ID of the group
    g.set('class', 'interactive-panel')  # The class for JS to find all panels

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
    # Makes sure the text doesn't block clicks on the circle
    text_elem.set('pointer-events', 'none')
    text_elem.text = display_label
# --- FINAL, UNIFIED PROCESSING FUNCTION ---


def get_layer_colors(layer_name: str) -> Dict[str, str]:
    """
    Get colors for different panel types within each layer.
    Returns dict with colors for Horizontal, Vertical, Square/Other
    """
    layer_color_schemes = {
        'GFRC': {
            'Horizontal': "#4A90E2",  # Blue
            'Vertical': "#7ED321",    # Green
            'Square/Other': "#F5A623"  # Orange
        },
        'GLASS': {
            'Horizontal': "#50E3C2",  # Teal
            'Vertical': "#B8E986",    # Light Green
            'Square/Other': "#F8E71C"  # Yellow
        },
        'glass_40%': {
            'Horizontal': "#9013FE",  # Purple
            'Vertical': "#FF6B6B",    # Red
            'Square/Other': "#4ECDC4"  # Turquoise
        },
        'glass_30%': {
            'Horizontal': "#FF9500",  # Orange
            'Vertical': "#5AC8FA",    # Light Blue
            'Square/Other': "#FFCC02"  # Gold
        },
        'glass_50%': {
            'Horizontal': "#FF2D92",  # Pink
            'Vertical': "#30D158",    # Mint Green
            'Square/Other': "#BF5AF2"  # Violet
        },
        'glass_opaque': {
            'Horizontal': "#8E8E93",  # Gray
            'Vertical': "#AF52DE",    # Purple
            'Square/Other': "#FF9F0A"  # Orange Yellow
        },
        'glass_80%': {
            'Horizontal': "#007AFF",  # System Blue
            'Vertical': "#34C759",    # System Green
            'Square/Other': "#FF3B30"  # System Red
        }
    }

    # Default colors if layer not found
    default_colors = {
        'Horizontal': "#D3D3D3",  # Light Gray
        'Vertical': "#A9A9A9",    # Dark Gray
        'Square/Other': "#696969"  # Dim Gray
    }

    return layer_color_schemes.get(layer_name.upper(), default_colors)


def process_layer_rectangles(layer_element, layer_name: str, filename_prefix: str,
                             base_svg_filename: str, axis_labels: List[Dict], floor_labels: List[Dict]) -> List[Dict]:
    """
    FINAL CORRECTED VERSION: Restores the fill color for the panel rectangles
    while keeping the human-readable labels and dynamic circle colors.
    """
    if layer_element is None:
        return []
    paths = [child for child in layer_element if child.tag.endswith('path')]
    all_rectangles = []
    for path in paths:
        if (path_d := path.get('d')) and (coords := parse_path_coordinates(path_d)) and (bounds := get_rectangle_bounds(coords)):
            all_rectangles.append({'element': path, 'bounds': bounds, 'center': get_rectangle_center(
                coords), 'original_d': path_d})

    filtered_rects = [
        r for r in all_rectangles if should_include_panel(r, layer_name)]
    filtered_rects.sort(key=lambda r: r['bounds']['min_y'])

    results = []
    panel_counter = 0
    # Get the color scheme for this layer
    layer_colors = get_layer_colors(layer_name)

    for i, rect in enumerate(filtered_rects):
        panel_counter += 1
        center = rect['center']
        panel_type = classify_panel_type(rect['bounds'])

        # --- ID and Label Generation ---
        signature = f"{base_svg_filename}-{layer_name}-{rect['original_d']}-{i}".encode(
        )
        unique_id = hashlib.md5(signature).hexdigest()
        display_label = f"{filename_prefix}-{panel_counter}"

        # --- START OF RESTORED CODE ---
        # Get the fill color for the panel based on its orientation
        panel_fill_color = layer_colors.get(
            panel_type, '#808080')  # Default to gray
        fill_opacity = "0.6" if 'glass' in layer_name.lower() else "0.7"

        # Modify the panel rectangle's appearance
        path_element = rect['element']
        path_element.set('fill', panel_fill_color)
        path_element.set('fill-opacity', fill_opacity)
        path_element.set('stroke', 'black')
        path_element.set('stroke-width', '1.5')
        # --- END OF RESTORED CODE ---

        # Add the circle with its own dynamic color and readable label
        add_circle_with_text(layer_element, unique_id,
                             display_label, center[0], center[1], panel_type)

        # Create the clean data record for the database
        results.append({
            'element_id': unique_id,
            'element_type': layer_name,
            'zone_name': filename_prefix,
            'svg_file_name': f"{base_svg_filename}.svg",
            'axis_span': determine_axis_range(center[0], axis_labels),
            'floor_level': determine_floor(center[1], floor_labels),
            'contractor': '', 'block': '',
            'plan_file': f"{base_svg_filename}.svg",
            'x_coord': center[0],
            'y_coord': center[1],
            'panel_orientation': panel_type
        })
    print(f"Processed {len(results)} panels in layer '{layer_name}'")
    return results


def process_elements_for_database(root: ET.Element, layer_name: str, filename_prefix: str, base_svg_filename: str, svg_context: dict) -> List[Dict]:
    """
    MODE 1: Processes a layer to extract data for the database.
    Does NOT modify the SVG.
    """
    layer_element = root.find(f".//*[@id='{layer_name}']")
    if layer_element is None:
        return []

    paths = [child for child in layer_element if child.tag.endswith('path')]
    results = []

    for i, path_element in enumerate(paths):
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            center = get_rectangle_center(coords)
            panel_type = classify_panel_type(get_rectangle_bounds(coords))

            # Create the unique, permanent ID
            signature = f"{base_svg_filename}-{layer_name}-{path_d}-{i}".encode()
            unique_id = hashlib.md5(signature).hexdigest()

            # Create the clean data record for the database
            results.append({
                'element_id': unique_id,
                'element_type': layer_name,
                'zone_name': filename_prefix,
                'svg_file_name': f"{base_svg_filename}.svg",
                'axis_span': 'N/A',  # Determined by front-end
                'floor_level': 'N/A',  # Determined by front-end
                'contractor': svg_context.get('contractor'),  # From JSON
                'block': svg_context.get('block'),           # From JSON
                'plan_file': f"{base_svg_filename}.svg",
                'x_coord': center[0],
                'y_coord': center[1],
                'panel_orientation': panel_type
            })
    print(
        f"Extracted {len(results)} panels from layer '{layer_name}' for database.")
    return results


def process_elements_for_svg_creation(root: ET.Element, layer_name: str, filename_prefix: str):
    """
    MODE 2: Processes a layer to visually modify an SVG file.
    Adds colors and clickable overlays.
    """
    layer_element = root.find(f".//*[@id='{layer_name}']")
    if layer_element is None:
        return

    paths = [child for child in layer_element if child.tag.endswith('path')]
    panel_counter = 0

    for i, path_element in enumerate(paths):
        if (path_d := path_element.get('d')) and (coords := parse_path_coordinates(path_d)):
            panel_counter += 1
            center = get_rectangle_center(coords)
            bounds = get_rectangle_bounds(coords)
            panel_type = classify_panel_type(bounds)

            # Create a readable label for the SVG
            display_label = f"{filename_prefix}-{panel_counter}"

            # Apply visual styling to the panel
            # (Assuming you have a get_layer_colors function from before)
            # path_element.set('fill', get_layer_colors(layer_name)[panel_type])
            # path_element.set('fill-opacity', '0.7')

            # Add the clickable circle and label
            # (Assuming you have an add_circle_with_text function from before)
            # add_circle_with_text(layer_element, unique_id, display_label, center[0], center[1], panel_type)
    print(
        f"Visually processed {panel_counter} panels in layer '{layer_name}'.")


def process_single_svg_file(svg_file_path: str, output_folder: str) -> List[Dict]:
    print(f"\n--- Processing: {os.path.basename(svg_file_path)} ---")
    filename_prefix = get_short_filename_prefix(svg_file_path)
    base_name = Path(svg_file_path).stem
    output_svg_path = os.path.join(output_folder, f"{base_name}_processed.svg")
    try:
        tree = ET.parse(svg_file_path)
        root = tree.getroot()
    except Exception as e:
        print(f"Error parsing SVG: {e}")
        return []
    axis_labels, floor_labels, layers_to_process = extract_axis_labels(
        root), extract_floor_labels(root), find_layers_to_process(root)
    if not layers_to_process:
        return []
    all_results = []
    for layer_element, layer_name in layers_to_process:
        all_results.extend(process_layer_rectangles(
            layer_element, layer_name, filename_prefix, base_name, axis_labels, floor_labels))
    try:
        ET.register_namespace('', 'http://www.w3.org/2000/svg')
        tree.write(output_svg_path, encoding='utf-8', xml_declaration=True)
        print(f"Saved modified SVG to: {output_svg_path}")
    except Exception as e:
        print(f"Error saving SVG: {e}")
    return all_results


def process_folder_svg_files(input_folder: str, output_folder: str = '') -> List[Dict]:
    if not output_folder:
        output_folder = os.path.join(input_folder, "processed")
    os.makedirs(output_folder, exist_ok=True)
    svg_files = glob.glob(os.path.join(input_folder, "*.svg"))
    if not svg_files:
        return []
    all_results = []
    for svg_file in svg_files:
        try:
            all_results.extend(
                process_single_svg_file(svg_file, output_folder))
        except Exception as e:
            print(f"Error on {os.path.basename(svg_file)}: {e}")
    return all_results


def insert_data_into_database(data_list: List[Dict]):
    """Connects to MySQL and inserts/updates data in chunks."""
    if not data_list:
        print("No data to insert.")
        return
    connection, cursor = None, None
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        cursor = connection.cursor()
        print(f"\nConnecting to '{DB_CONFIG['database']}'...")
        query = ("INSERT INTO elements (element_id, element_type, zone_name, svg_file_name, axis_span, "
                 "floor_level, contractor, block, plan_file, x_coord, y_coord, panel_orientation) VALUES (%(element_id)s, "
                 "%(element_type)s, %(zone_name)s, %(svg_file_name)s, %(axis_span)s, %(floor_level)s, %(contractor)s, "
                 "%(block)s, %(plan_file)s, %(x_coord)s, %(y_coord)s, %(panel_orientation)s) ON DUPLICATE KEY UPDATE "
                 "element_type=VALUES(element_type), zone_name=VALUES(zone_name), contractor=VALUES(contractor), block=VALUES(block)")

        chunk_size, total = 500, len(data_list)
        print(f"Preparing to insert/update {total} rows...")
        for i in range(0, total, chunk_size):
            cursor.executemany(query, data_list[i:i + chunk_size])
        connection.commit()
        print(f"\n✅ Success! Database operation completed for {total} items.")
    except (mysql.connector.Error, _mysql_connector.MySQLInterfaceError) as err:
        print(f"\n❌ Database Error: {err}")
        if connection:
            connection.rollback()
    finally:
        if cursor:
            cursor.close()
        if connection and connection.is_connected():
            connection.close()
            print("Database connection closed.")


def main():
    # =========================================================================
    # --- SCRIPT CONFIGURATION ---
    #
    # Choose which operation you want to perform by setting the mode.
    #
    # 'UPDATE_DATABASE': Reads raw SVGs and JSON files to populate your MySQL table.
    # 'CREATE_PROCESSED_SVGS': Reads raw SVGs and saves new visually modified files.
    #
    OPERATION_MODE = 'UPDATE_DATABASE'
    # =========================================================================

    print(f"--- Running in mode: {OPERATION_MODE} ---")

    # --- Load configuration files ---
    try:
        with open('config.json', 'r', encoding='utf-8') as f:
            config_data = json.load(f)
        with open('svg_group_config.json', 'r', encoding='utf-8') as f:
            group_config_data = json.load(f)
        print("Configuration files loaded.")
    except Exception as e:
        print(f"CRITICAL ERROR: Could not load JSON config files. {e}")
        return

    input_folder = r"C:\xampp\htdocs\public_html\ghom\svg"
    output_folder = r"C:\xampp\htdocs\public_html\ghom\processed"
    os.makedirs(output_folder, exist_ok=True)

    # Get a unique list of all SVG files from the config
    svg_files_to_process = sorted(list({zone['svgFile'] for zones in config_data.values(
    ) if isinstance(zones, list) for zone in zones}))

    all_panel_data = []

    for svg_filename in svg_files_to_process:
        svg_file_path = os.path.join(input_folder, svg_filename)
        if not os.path.exists(svg_file_path):
            print(f"Warning: SVG not found, skipping: {svg_file_path}")
            continue

        print(f"\n--- Processing: {svg_filename} ---")
        base_name = Path(svg_file_path).stem
        filename_prefix = get_short_filename_prefix(svg_file_path)

        try:
            tree = ET.parse(svg_file_path)
            root = tree.getroot()
        except Exception as e:
            print(f"Error parsing SVG: {e}")
            continue

        if OPERATION_MODE == 'UPDATE_DATABASE':
            svg_context = get_svg_context(
                svg_filename, config_data, group_config_data)
            for layer_name in ['GFRC', 'GLASS']:
                all_panel_data.extend(process_elements_for_database(
                    root, layer_name, filename_prefix, base_name, svg_context))

        elif OPERATION_MODE == 'CREATE_PROCESSED_SVGS':
            for layer_name in ['GFRC', 'GLASS']:
                process_elements_for_svg_creation(
                    root, layer_name, filename_prefix)
            # Save the modified SVG file
            output_svg_path = os.path.join(
                output_folder, f"{base_name}_processed.svg")
            tree.write(output_svg_path, encoding='utf-8', xml_declaration=True)
            print(f"Saved modified SVG to: {output_svg_path}")

    # --- FINAL ACTION ---
    if OPERATION_MODE == 'UPDATE_DATABASE':
        if all_panel_data:
            insert_data_into_database(all_panel_data)
        else:
            print("\nNo panel data was extracted to update the database.")

    print("\nProcessing complete.")


if __name__ == "__main__":
    main()
