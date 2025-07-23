import pandas as pd
import numpy as np


def transform_panel_data(input_file_path, output_file_path):
    """
    Reads panel data from an Excel file, transforms it, and saves it to a new Excel file.

    Args:
        input_file_path (str): The path to the input Excel file (e.g., 'Panels.xlsx').
        output_file_path (str): The path to save the transformed Excel file (e.g., 'transformed_panels.xlsx').
    """
    try:
        # Read the Excel file. Assuming the table is on the first sheet.
        # The first column 'زیرسازی و پنل بتنی' is used as the main label column.
        df = pd.read_excel(input_file_path, header=1)

        # Rename columns for easier access
        # The first column contains both the main title and floor names
        df.rename(columns={df.columns[0]: 'Floor_Raw',
                  df.columns[1]: 'zone'}, inplace=True)

        # Remove the total row ('جمع') at the bottom if it exists
        df = df[df['Floor_Raw'].str.contains('جمع', na=False) == False]

        # Set index to Floor_Raw and zone to handle the unpivoting
        df.set_index(['Floor_Raw', 'zone'], inplace=True)

        # Stack the dataframe to convert it from wide to long format
        long_df = df.stack().reset_index()
        long_df.columns = ['Floor_Raw', 'zone', 'address', 'total_in_batch']

        # Replace non-numeric placeholders like '-' with 0 and convert to integer
        long_df['total_in_batch'] = pd.to_numeric(
            long_df['total_in_batch'], errors='coerce').fillna(0).astype(int)

        # Filter out rows where the count is zero
        long_df = long_df[long_df['total_in_batch'] > 0].copy()

        # Extract the numeric floor number from the 'Floor_Raw' column (e.g., 'طبقه1' -> 1)
        long_df['Floor'] = long_df['Floor_Raw'].str.extract(
            '(\d+)').astype(int)

        # Expand the dataframe based on the 'total_in_batch' count
        expanded_df = long_df.loc[long_df.index.repeat(
            long_df['total_in_batch'])].copy()

        # Generate the 'instance_number' for each group
        expanded_df['instance_number'] = expanded_df.groupby(
            ['address', 'Floor']).cumcount() + 1

        # Create the 'full_address_identifier'
        expanded_df['full_address_identifier'] = (
            expanded_df['address'] +
            '-f' + expanded_df['Floor'].astype(str) +
            '-' + expanded_df['instance_number'].astype(str)
        )

        # Add the constant 'type' column
        expanded_df['type'] = 'terrace edge'

        # Create the 'Proritization' column
        expanded_df['Proritization'] = 'zone ' + \
            expanded_df['zone'].astype(str)

        # Create a sequential ID column
        expanded_df.insert(0, 'id', np.arange(1, len(expanded_df) + 1))

        # Select and reorder columns to match the desired final output
        final_df = expanded_df[[
            'id',
            'address',
            'Floor',
            'instance_number',
            'total_in_batch',
            'full_address_identifier',
            'type',
            'Proritization'
        ]]

        # Save the final dataframe to a new Excel file
        final_df.to_excel(output_file_path, index=False)
        print(
            f"Transformation complete! The new file has been saved as '{output_file_path}'")

    except FileNotFoundError:
        print(f"Error: The file '{input_file_path}' was not found.")
    except Exception as e:
        print(f"An error occurred: {e}")

# --- How to use the script ---
# 1. Make sure you have pandas and openpyxl installed:
#    pip install pandas openpyxl numpy

# 2. Place your 'Panels.xlsx' file in the same directory as this script.
# 3. Run the script.


if __name__ == '__main__':
    # Define the input and output file names
    input_filename = 'Panels.xlsx'
    output_filename = 'transformed_panels.xlsx'

    # Execute the transformation
    transform_panel_data(input_filename, output_filename)
