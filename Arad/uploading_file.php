<!DOCTYPE html>
<?php
$uploadOk = 1;

// Process file upload
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get target directory from input
    $target_dir = $_POST["dirname"] . "/";

    // Check if directory is specified
    if (!empty($_POST["dirname"])) {
        // Create the directory if it doesn't exist
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
            echo "Directory created: $target_dir <br>";
        }
    } else {
        echo "Error: Please specify a directory name.<br>";
        $uploadOk = 0;
    }

    // Check if file was uploaded without errors
    if (isset($_FILES["fileToUpload"]) && $_FILES["fileToUpload"]["error"] == 0) {
        $allowed_ext = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $file_name = $_FILES["fileToUpload"]["name"];
        $file_type = $_FILES["fileToUpload"]["type"];
        $file_size = $_FILES["fileToUpload"]["size"];

        // Verify file extension
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!array_key_exists($ext, $allowed_ext)) {
            echo "Error: Please select a valid file format.<br>";
            $uploadOk = 0;
        }

        // Verify file size - 2MB max
        $maxsize = 2 * 1024 * 1024;
        if ($file_size > $maxsize) {
            echo "Error: File size is larger than the allowed limit (2MB).<br>";
            $uploadOk = 0;
        }

        // Verify MIME type of the file
        if (!in_array($file_type, $allowed_ext)) {
            echo "Error: Invalid file type.<br>";
            $uploadOk = 0;
        }

        // Check whether file already exists in the target directory
        $target_file = $target_dir . basename($file_name);
        if (file_exists($target_file)) {
            echo "Error: The file $file_name already exists.<br>";
            $uploadOk = 0;
        }

        // Upload the file if everything is okay
        if ($uploadOk === 1) {
            if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
                echo "The file " . $file_name . " has been uploaded successfully.<br>";
            } else {
                echo "Sorry, there was an error uploading your file.<br>";
            }
        }
    } else {
        echo "Error: " . $_FILES["fileToUpload"]["error"] . "<br>";
    }
}
?>

<html>

<body>
    <h2>Upload Image to a Specified Directory</h2>
    <form action="uploading_file.php" method="post" enctype="multipart/form-data">
        Directory: <input type="text" name="dirname" id="dirname" required> <br><br>
        Select image to upload:
        <input type="file" name="fileToUpload" id="fileToUpload" required> <br><br>
        <input type="submit" value="Upload Image" name="submit">
    </form>
</body>

</html>