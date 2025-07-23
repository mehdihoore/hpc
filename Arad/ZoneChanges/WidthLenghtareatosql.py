data = [
    ["CP-S-01", 1582, 960, 2.34],
    ["CP-E-01", 1582, 960, 2.21],
    ["CP-W-01", 1582, 960, 2.21],
    ["CP-N-01", 1582, 960, 2.34],
    ["CP-NW", 556, 964, 1.39],
    ["CP-NE", 556, 964, 1.39],
    ["CP-SW", 556, 964, 1.56],
    ["CP-SE", 556, 964, 1.56],
    ["CP-S-02", 1582, 964, 1.93],
    ["CP-S-03", 1582, 1146, 2.58],
    ["CP-S-04", 1582, 1110, 3.07],
    ["CP-S-05", 1582, 1146, 3.07],
    ["CP-E-02", 500, 964, 1.34],
    ["CP-E-03", 1398, 960, 1.7],
    ["CP-E-04", 1682, 1113, 3.51],
    ["CP-E-05", 1492, 1114, 2.63],
    ["CP-E-06", 1492, 960, 1.81],
    ["CP-E-07", 1492, 1113, 3.19],
    ["CP-W-02", 1492, 1113, 2.63],
    ["CP-W-03", 1492, 960, 1.81],
    ["CP-W-04", 1492, 1113, 3.19],
    ["CP-W-05", 723, 1113, 1.89],
    ["CP-W-06", 721, 964, 1.49],
    ["CP-W-07", 1106, 960, 1.66]
]

with open(r"C:\xampp\htdocs\public_html\Arad\ZoneChanges\update_panels.sql", "w") as f:
    f.write("-- SQL update commands for hpc_panels\n\n")
    for row in data:
        address, width, length, area = row
        sql = f"UPDATE hpc_panels SET area = {area}, width = {width}, length = {length} WHERE address = '{address}';\n"
        f.write(sql)

print("SQL commands saved to update_panels.sql")
