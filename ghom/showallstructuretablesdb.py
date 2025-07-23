import mysql.connector

DB_CONFIG = {
    'host': 'localhost',
    'database': 'hpc_ghom',
    'user': 'root',
    'password': ''
}

output_file = 'hpc_ghom_structure.sql'

try:
    # Connect to the database
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()

    # Get all table names
    cursor.execute("SHOW TABLES")
    tables = cursor.fetchall()

    with open(output_file, 'w', encoding='utf-8') as f:
        for (table_name,) in tables:
            # Get CREATE TABLE statement for each table
            cursor.execute(f"SHOW CREATE TABLE `{table_name}`")
            result = cursor.fetchone()
            create_statement = result[1]

            # Write to file with formatting
            f.write("-- -------------------------------\n")
            f.write(f"-- Structure for table `{table_name}`\n")
            f.write("-- -------------------------------\n")
            f.write(create_statement + ";\n\n")

    print(f"✅ All table structures saved to {output_file}")

except mysql.connector.Error as err:
    print("Error:", err)

finally:
    if 'cursor' in locals():
        cursor.close()
    if 'conn' in locals():
        conn.close()
