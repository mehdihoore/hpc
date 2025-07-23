import rhinoscriptsyntax as rs
import scriptcontext as sc
import Rhino

def flatten_usertxt_fields():
    sc.doc = Rhino.RhinoDoc.ActiveDoc

    # Get all text annotations (TextEntity objects)
    text_ids = rs.ObjectsByType(512)  # OR use rs.filter.annotation
    if not text_ids:
        print("No text objects found.")
        return

    count = 0
    for tid in text_ids:
        original = rs.TextObjectText(tid)  # get current text
        if original and original.startswith("%<UserText(") and original.endswith(">%"):
            # Evaluate field
            rhobj = rs.coercerhinoobject(tid, True)
            parsed = Rhino.RhinoApp.ParseTextField(original, rhobj, None)
            if parsed:
                rs.TextObjectText(tid, parsed)  # replace with evaluated value
                count += 1

    rs.Redraw()
    print(f"Flattened {count} UserText field(s).")

if __name__ == "__main__":
    flatten_usertxt_fields()
