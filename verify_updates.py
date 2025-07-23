import csv
import mysql.connector
from decimal import Decimal


def verify_panel_areas():
    """
    Connects to a MySQL database and verifies that the 'area' values
    in the 'hpc_panels' table match the values in a given CSV file.
    """
    # --- IMPORTANT: Fill in your database credentials below ---
    db_config = {
        'host': 'localhost',  # e.g., 'localhost' or an IP address
        'user': 'root',
        'password': '',
        'database': 'alumglas_hpc'
    }

    csv_filename = 'updatearea.csv'
    table_name = 'hpc_panels'

    mismatches = 0
    matches = 0
    not_found = 0

    try:
        # Establish the database connection
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()

        print("Successfully connected to the database.")
        print("-" * 30)

        with open(csv_filename, mode='r', encoding='utf-8') as csv_file:
            csv_reader = csv.DictReader(csv_file)

            for row in csv_reader:
                address = row.get('address', '').strip()
                csv_area = row.get('area', '').strip()

                if not address or not csv_area:
                    continue

                # Prepare and execute the SELECT query to get the current area
                query = f"SELECT `area` FROM `{table_name}` WHERE `address` = %s"
                cursor.execute(query, (address,))
                result = cursor.fetchone()

                if result:
                    # The result is a tuple, e.g., (Decimal('1.450'),)
                    db_area = result[0]

                    # Compare the database area with the CSV area.
                    # We cast both to Decimal for accurate comparison.
                    if db_area == Decimal(csv_area):
                        print(
                            f"✅ MATCH: Address '{address}' has the correct area ({csv_area}).")
                        matches += 1
                    else:
                        print(
                            f"❌ MISMATCH: Address '{address}' - Expected: {csv_area}, Found: {db_area}.")
                        mismatches += 1
                else:
                    print(
                        f"❓ NOT FOUND: Address '{address}' was not found in the database.")
                    not_found += 1

    except mysql.connector.Error as err:
        print(f"Database Error: {err}")
        return
    except FileNotFoundError:
        print(f"Error: The file '{csv_filename}' was not found.")
        return
    except Exception as e:
        print(f"An unexpected error occurred: {e}")
        return
    finally:
        # Make sure to close the connection
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()
            print("-" * 30)
            print("Database connection closed.")

    # Print a final summary
    print("\n--- Verification Summary ---")
    print(f"✅ Correctly updated: {matches}")
    print(f"❌ Mismatches found:  {mismatches}")
    print(f"❓ Addresses not found: {not_found}")
    print("--------------------------")


# Run the verification function
verify_panel_areas()
