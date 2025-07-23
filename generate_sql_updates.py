import csv


def create_sql_update_script():
    """
    Reads a CSV file with 'address' and 'area' columns and generates
    a .sql file with UPDATE statements to run in phpMyAdmin.
    """
    csv_filename = 'updatearea.csv'
    sql_filename = 'update_areas.sql'
    table_name = 'hpc_panels'

    try:
        with open(csv_filename, mode='r', encoding='utf-8') as csv_file:
            # Use DictReader to easily access columns by header name
            csv_reader = csv.DictReader(csv_file)

            with open(sql_filename, mode='w', encoding='utf-8') as sql_file:
                sql_file.write(
                    f"-- Generated SQL commands for updating {table_name}\n\n")

                count = 0
                for row in csv_reader:
                    # Get address and area, stripping any extra whitespace
                    address = row.get('address', '').strip()
                    area = row.get('area', '').strip()

                    # Basic validation to ensure we have data to work with
                    if not address or not area:
                        print(f"Skipping row with missing data: {row}")
                        continue

                    # Create the SQL UPDATE statement
                    # We wrap the address in single quotes for the SQL query
                    update_command = f"UPDATE `{table_name}` SET `area` = {area} WHERE `address` = '{address}';\n"

                    sql_file.write(update_command)
                    count += 1

        print(
            f"Successfully generated {count} SQL commands in '{sql_filename}'")

    except FileNotFoundError:
        print(f"Error: The file '{csv_filename}' was not found.")
        print("Please make sure the CSV file is in the same directory as the script and is named correctly.")
    except Exception as e:
        print(f"An unexpected error occurred: {e}")


# Run the function
create_sql_update_script()
