import numpy as np
from PIL import Image
import matplotlib.pyplot as plt
from sklearn.cluster import KMeans

def kmeans_image_compression(image_path, n_colors=16, output_path='turtles.png'):
    # Load image and convert to RGB
    image = Image.open(image_path)
    image = image.convert("RGB")
    img_data = np.array(image)
    original_shape = img_data.shape

    # Flatten the image (height x width, 3)
    flat_img = img_data.reshape(-1, 3)

    # Apply KMeans
    print(f"Applying KMeans with {n_colors} colors...")
    kmeans = KMeans(n_clusters=n_colors, random_state=42, n_init='auto')  # n_init='auto' suppresses warning in sklearn >= 1.4
    kmeans.fit(flat_img)
    labels = kmeans.predict(flat_img)
    compressed_flat_img = kmeans.cluster_centers_[labels].astype('uint8')

    # Reshape to original image shape
    compressed_img = compressed_flat_img.reshape(original_shape)

    # Save compressed image
    compressed_image = Image.fromarray(compressed_img)
    compressed_image.save(output_path)

    # Show original and compressed images side by side
    plt.figure(figsize=(10, 5))
    plt.subplot(1, 2, 1)
    plt.title("Original")
    plt.imshow(image)
    plt.axis('off')

    plt.subplot(1, 2, 2)
    plt.title(f"Compressed ({n_colors} colors)")
    plt.imshow(compressed_image)
    plt.axis('off')

    plt.tight_layout()
    plt.show()

# Example usage
kmeans_image_compression('turtles.png')
