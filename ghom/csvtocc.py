import pandas as pd
import mysql.connector

# DB configuration
DB_CONFIG = {
    'host': 'localhost',
    'database': 'hpc_ghom',
    'user': 'root',
    'password': ''
}

# Read CSV files
df1 = pd.read_csv('elements (1).csv')
df2 = pd.read_csv('elements.csv')

# Merge to update axis_span
df2_updated = df2.merge(
    df1[['element_id', 'axis_span']],
    on='element_id',
    how='left',
    suffixes=('', '_new')
)

# Update axis_span in df2 if exists in df1
df2_updated['axis_span'] = df2_updated['axis_span_new'].combine_first(
    df2_updated['axis_span'])
df2_updated = df2_updated.drop(columns=['axis_span_new'])

# Save back to elements.csv
df2_updated.to_csv('elements.csv', index=False)

print("elements.csv updated successfully.")

# Update database
conn = None
cursor = None

try:
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()

    for _, row in df2_updated.iterrows():
        element_id = row['element_id']
        axis_span = row['axis_span']

        # Prepare and execute UPDATE query
        cursor.execute(
            "UPDATE elements SET axis_span = %s WHERE element_id = %s",
            (axis_span, element_id)
        )

    conn.commit()
    print("Database updated successfully.")

except mysql.connector.Error as err:
    print("Database error:", err)

finally:
    if cursor is not None:
        cursor.close()
    if conn is not None:
        conn.close()
