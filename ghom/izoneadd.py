import xml.etree.ElementTree as ET
import argparse
import re

# --- Configuration ---
Y_OFFSET_FACTOR = 1.4
NEARBY_TOLERANCE_X = 10 # Increased tolerance slightly
NEARBY_TOLERANCE_Y = 10 # Increased tolerance slightly
NEW_ID_SUFFIX = "_zon_label"
SVG_NAMESPACE = "http://www.w3.org/2000/svg" # The standard SVG namespace URI

def is_purely_numeric(s):
    if s is None:
        return False
    return s.strip().isdigit()

def create_zon_text_element(original_text_el, new_id_base, ns_map):
    """Creates a new 'Zon' text element based on an original numeric text element."""
    # Use the registered namespace for creating new elements
    text_tag = f"{{{SVG_NAMESPACE}}}text"
    zon_text_el = ET.Element(text_tag)
    zon_text_el.text = "Zon"

    attributes_to_copy = ['font-family', 'font-size', 'fill', 'text-anchor', 'style']
    for attr in attributes_to_copy:
        if original_text_el.get(attr) is not None: # Check if attribute exists
            zon_text_el.set(attr, original_text_el.get(attr))

    original_x_str = original_text_el.get('x', '0')
    original_y_str = original_text_el.get('y', '0')
    font_size_str = original_text_el.get('font-size', '10')

    try:
        original_x = float(original_x_str)
        original_y = float(original_y_str)
        font_size = float(font_size_str)
    except ValueError:
        print(f"Warning: Could not parse x, y, or font-size for element ID {original_text_el.get('id')}. Using defaults for positioning 'Zon' label.")
        original_x, original_y, font_size = 0, 0, 10


    new_y = original_y + (font_size * Y_OFFSET_FACTOR)
    zon_text_el.set('x', str(original_x))
    zon_text_el.set('y', str(new_y))

    original_id = original_text_el.get('id', new_id_base)
    zon_text_el.set('id', f"{original_id}{NEW_ID_SUFFIX}")

    return zon_text_el

def zon_label_exists_nearby(numeric_el, all_text_elements_in_group, ns_map):
    """
    Checks if a "Zon" label already exists near the given numeric element
    WITHIN THE SAME IMMEDIATE PARENT GROUP.
    """
    try:
        num_x = float(numeric_el.get('x', '0'))
        num_y = float(numeric_el.get('y', '0'))
        num_font_size = float(numeric_el.get('font-size', '10'))
        expected_zon_y = num_y + (num_font_size * Y_OFFSET_FACTOR)
    except ValueError:
        return False

    text_tag_search = f"{{{SVG_NAMESPACE}}}text"

    for text_el in all_text_elements_in_group:
        if text_el.tag == text_tag_search and text_el.text and text_el.text.strip().lower() == "zon":
            try:
                zon_x = float(text_el.get('x', '0'))
                zon_y = float(text_el.get('y', '0'))

                if (abs(zon_x - num_x) < NEARBY_TOLERANCE_X and
                    abs(zon_y - expected_zon_y) < NEARBY_TOLERANCE_Y):
                    return True
            except ValueError:
                continue
    return False

def process_svg(input_svg_path, output_svg_path):
    try:
        # Register the default SVG namespace.
        # This helps ElementTree parse it correctly and potentially use it in output.
        # Using an empty string as the prefix '' maps it to the default namespace.
        ET.register_namespace('', SVG_NAMESPACE)
        # For finding elements, we will use this map
        ns_map = {'svg': SVG_NAMESPACE}

        tree = ET.parse(input_svg_path)
        root = tree.getroot()
    except ET.ParseError as e:
        print(f"Error parsing SVG file: {e}")
        return
    except FileNotFoundError:
        print(f"Error: Input file '{input_svg_path}' not found.")
        return

    # We'll iterate through groups or direct children of root if no groups are found
    # to better scope the "nearby" check.
    
    elements_to_add_to_parent = {} # Store as {parent_element: [list_of_new_zon_elements]}
    unique_id_counter = 0
    
    # Iterate through all elements and identify potential parents for text (like <g> or the root)
    for parent_candidate in root.iter():
        # Find direct children <text> elements of this parent_candidate
        child_text_elements = [child for child in parent_candidate if child.tag == f"{{{SVG_NAMESPACE}}}text"]
        if not child_text_elements:
            continue

        # Create a temporary list of all text elements within this specific parent for the nearby check.
        # This is more accurate than checking all_text_elements in the entire document.
        local_text_for_nearby_check = list(child_text_elements)


        for el in child_text_elements:
            text_content = el.text
            if is_purely_numeric(text_content):
                el_id = el.get('id', 'N/A')
                print(f"Found numeric text: '{text_content.strip()}' (ID: {el_id}, Parent: {parent_candidate.tag})")

                if not zon_label_exists_nearby(el, local_text_for_nearby_check, ns_map):
                    print(f"  -> Adding 'Zon' label.")
                    new_id_base_for_zon = el.get('id', f"num_{unique_id_counter}")
                    unique_id_counter += 1
                    zon_el = create_zon_text_element(el, new_id_base_for_zon, ns_map)
                    
                    if parent_candidate not in elements_to_add_to_parent:
                        elements_to_add_to_parent[parent_candidate] = []
                    elements_to_add_to_parent[parent_candidate].append(zon_el)
                    
                    # Add the newly created zon_el to the local_text_for_nearby_check
                    # so that subsequent numbers in the same group don't also get a new "Zon"
                    # if this one is close enough.
                    local_text_for_nearby_check.append(zon_el)
                else:
                    print(f"  -> 'Zon' label likely already exists nearby within the same group. Skipping.")

    # Add new elements to their respective parents
    for parent, new_elements_list in elements_to_add_to_parent.items():
        for new_element in new_elements_list:
            parent.append(new_element)

    try:
        # When writing, ElementTree should use the registered default namespace if possible,
        # or the ns0, ns1, etc., if it can't avoid it for some reason.
        tree.write(output_svg_path, encoding="utf-8", xml_declaration=True, default_namespace=SVG_NAMESPACE)
        print(f"\nProcessed SVG saved to: {output_svg_path}")
    except TypeError: # Older ElementTree versions might not support default_namespace
        try:
            print("Writing without explicit default_namespace (older ElementTree?). Output might have ns0 prefixes.")
            tree.write(output_svg_path, encoding="utf-8", xml_declaration=True)
            print(f"\nProcessed SVG saved to: {output_svg_path}")
        except Exception as e_fallback:
            print(f"Error writing output SVG file (fallback): {e_fallback}")
    except Exception as e:
        print(f"Error writing output SVG file: {e}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Add "Zon" labels to numeric text in an SVG file.')
    parser.add_argument('input_svg', help='Path to the input SVG file.')
    parser.add_argument('output_svg', help='Path for the output modified SVG file.')
    parser.add_argument('--y-offset-factor', type=float, default=Y_OFFSET_FACTOR,
                        help=f'Factor to multiply font-size by for Y offset of "Zon" (default: {Y_OFFSET_FACTOR})')
    parser.add_argument('--tolerance-x', type=float, default=NEARBY_TOLERANCE_X,
                        help=f'X-axis tolerance for detecting nearby "Zon" labels (default: {NEARBY_TOLERANCE_X})')
    parser.add_argument('--tolerance-y', type=float, default=NEARBY_TOLERANCE_Y,
                        help=f'Y-axis tolerance for detecting nearby "Zon" labels (default: {NEARBY_TOLERANCE_Y})')

    args = parser.parse_args()

    Y_OFFSET_FACTOR = args.y_offset_factor
    NEARBY_TOLERANCE_X = args.tolerance_x
    NEARBY_TOLERANCE_Y = args.tolerance_y

    process_svg(args.input_svg, args.output_svg)