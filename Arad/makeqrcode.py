import qrcode
import os

# --- Configuration ---

# The base URL for your QR codes. The ID will be appended to this.
BASE_URL = "https://alumglass.ir/Arad/panel_qr_code.php?id="

# The directory where the generated QR code images will be saved.
OUTPUT_DIR = "qrcodes"

# The range of IDs to generate QR codes for.
# range(1, 3001) will generate for IDs 1 to 3000.
START_ID = 1
END_ID = 3000

# --- Main Script ---


def generate_qr_codes():
    """
    Generates QR codes for a given range of IDs and saves them as PNG files.
    """
    print(f"Starting QR code generation...")

    # Create the output directory if it doesn't already exist.
    if not os.path.exists(OUTPUT_DIR):
        try:
            os.makedirs(OUTPUT_DIR)
            print(f"Successfully created directory: {OUTPUT_DIR}")
        except OSError as e:
            print(f"Error: Could not create directory {OUTPUT_DIR}. {e}")
            return  # Exit if directory creation fails

    # Loop through the specified range of IDs.
    for i in range(START_ID, END_ID + 1):
        # Construct the full URL for the current ID.
        url = f"{BASE_URL}{i}"

        # Configure the QR code object.
        qr = qrcode.QRCode(
            version=1,  # Controls the size of the QR Code.
            # Low error correction.
            error_correction=qrcode.constants.ERROR_CORRECT_L,
            box_size=10,  # Size of each box in pixels.
            border=4,   # Thickness of the border.
        )

        # Add the URL data to the QR code.
        qr.add_data(url)
        qr.make(fit=True)

        # Create an image from the QR Code instance.
        img = qr.make_image(fill_color="black", back_color="white")

        # Define the filename for the output image.
        filename = f"panel_qr_{i}.png"
        filepath = os.path.join(OUTPUT_DIR, filename)

        try:
            # Save the image file.
            img.save(filepath)
            # Print progress to the console.
            print(f"Successfully generated: {filepath} for URL: {url}")
        except Exception as e:
            print(f"Error: Could not save file {filepath}. {e}")

    print("\n-----------------------------------------")
    print(f"QR code generation complete!")
    print(
        f"All {END_ID - START_ID + 1} QR codes have been saved in the '{OUTPUT_DIR}' folder.")
    print("-----------------------------------------")


# --- Run the main function ---
if __name__ == "__main__":
    generate_qr_codes()
