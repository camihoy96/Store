<?php
session_start();
require('dbconn.php'); // Database connection

// Delete employee if requested
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $_SESSION['message'] = "Employee deleted successfully!";
    header("Location: employees.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees | Four ACC Angels Bakeshop</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== Global Styles ===== */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f4f9;
            transition: margin-left 0.3s;
        }

        /* ===== Header Styles ===== */
        .header {
            background: #2c3e50;
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .menu-icon {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.3s;
        }
        .menu-icon.close {
            transform: rotate(90deg);
        }
        .title h1 {
            margin: 0;
            font-size: 22px;
            color: white;
        }
        .dropdown-logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
        }
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: #f9f9f9;
            min-width: 120px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
        }
        .dropdown-content a {
            color: #333;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
        }
        .dropdown-content a:hover {
            background: #ddd;
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }

        /* ===== Sidebar Styles ===== */
        .sidebar {
            height: 100%;
            width: 250px;
            position: fixed;
            top: 0;
            left: -250px;
            background: #34495e;
            overflow-x: hidden;
            padding-top: 70px;
            transition: 0.3s;
            z-index: 999;
        }
        .sidebar.active {
            left: 0;
        }
        .sidebar a {
            padding: 12px 16px 12px 25px;
            text-decoration: none;
            font-size: 16px;
            color: white;
            display: block;
            transition: 0.2s;
        }
        .sidebar a:hover {
            background: #3d566e;
        }
        .dropdown-sidebar {
            width: 100%;
        }
        .dropdown-btn {
            width: 100%;
            padding: 12px 16px 12px 25px;
            text-align: left;
            border: none;
            background: #2c3e50;
            color: white;
            cursor: pointer;
            font-size: 16px;
            transition: 0.3s;
        }
        .dropdown-btn:hover {
            background: #3d566e;
        }
        .dropdown-content-sidebar {
            display: none;
            background: #2c3e50;
        }
        .dropdown-content-sidebar a {
            padding-left: 40px;
            font-size: 14px;
        }
        .dropdown-content-sidebar a:hover {
            background: #3d566e;
        }
        .show {
            display: block;
        }

        /* ===== Main Content Styles ===== */
        .main-content {
            padding: 20px;
            transition: margin-left 0.3s;
        }
        .main-content.shifted {
            margin-left: 250px;
        }
        .container {
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #2c3e50;
            color: white;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .action-btn {
            padding: 6px 12px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .edit-btn {
            background: #3498db;
            color: white;
        }
        .delete-btn {
            background: #e74c3c;
            color: white;
        }
        .add-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 15px;
            background: #27ae60;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .add-btn:hover {
            background: #2ecc71;
        }
        .message {
            padding: 10px;
            margin: 20px 0;
            background: #d4edda;
            color: #155724;
            border-radius: 4px;
        }

        /* Overlay when sidebar is active */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 998;
        }
        .overlay.active {
            display: block;
        }

        @media (min-width: 768px) {
            .sidebar {
                left: 0;
            }
            .main-content {
                margin-left: 250px;
            }
            .overlay {
                display: none !important;
            }
        }
        .modal {
        display: none;
        position: fixed;
        z-index: 1001;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 25px;
        border: 1px solid #888;
        width: 50%;
        max-width: 600px;
        border-radius: 5px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        animation: modalopen 0.3s;
    }
    
    @keyframes modalopen {
        from {opacity: 0; transform: translateY(-50px);}
        to {opacity: 1; transform: translateY(0);}
    }
    
    .close-modal {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.3s;
    }
    
    .close-modal:hover {
        color: #333;
    }
    
    /* Form Styles */
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: bold;
        color: #2c3e50;
    }
    
    .form-group input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 16px;
        transition: border 0.3s;
    }
    
    .form-group input:focus {
        border-color: #3498db;
        outline: none;
    }
    
    .submit-btn {
        background-color: #27ae60;
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
        transition: background 0.3s;
    }
    
    .submit-btn:hover {
        background-color: #2ecc71;
    }
    
    @media (max-width: 768px) {
        .modal-content {
            width: 90%;
            margin: 20% auto;
        }
    }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <button class="menu-icon" id="menuIcon">☰</button>
        <span id="currentTime" style="margin-left: 20px; font-weight: bold;"></span>
        <div class="title"><h1>Four ACC Angels Bakeshop</h1></div>
        <div class="dropdown">
            <img src="../image/mdc.logo.png" alt="Logo" class="logo dropdown-logo">
            <div class="dropdown-content">
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </div>

    <!-- Overlay -->
    <div class="overlay" id="overlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <a href="../Dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <!-- Product Category Dropdown -->
        <div class="dropdown-sidebar">
            <button class="dropdown-btn"><i class="fas fa-box"></i> Product Category ▾</button>
            <div class="dropdown-content-sidebar">
                <a href="item_reserve.php"><i class="fas fa-tasks"></i> Manage Reserve Items</a>
                <a href="view_products.php"><i class="fas fa-list"></i> View Items</a>
            </div>
        </div>
        <!-- Employees Dropdown -->
        <div class="dropdown-sidebar">
            <button class="dropdown-btn"><i class="fas fa-users"></i> Employees ▾</button>
            <div class="dropdown-content-sidebar">
                <a href="employees.php"><i class="fas fa-tasks"></i> Manage</a>
                <a href="add_employee.php"><i class="fas fa-user-plus"></i> Add</a>
                <a href="view_employees.php"><i class="fas fa-list"></i> View</a>
            </div>
        </div>
        <!-- Inventory Dropdown -->
        <div class="dropdown-sidebar">
            <button class="dropdown-btn"><i class="fas fa-warehouse"></i> Inventory ▾</button>
            <div class="dropdown-content-sidebar">
                <a href="inventory.php"><i class="fas fa-tasks"></i> Manage</a>
                <a href="add_inventory.php"><i class="fas fa-plus"></i> Add</a>
                <a href="view_inventory.php"><i class="fas fa-list"></i> View</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="container">
            <h1><i class="fas fa-users"></i> Manage Employees</h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="message">
                    <?= $_SESSION['message']; ?>
                    <?php unset($_SESSION['message']); ?>
                </div>
            <?php endif; ?>

           <button class="add-btn"><i class="fas fa-plus"></i> Add New Employee</button>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Phone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = $conn->query("SELECT * FROM employees ORDER BY id DESC");
                    while ($row = $result->fetch_assoc()):
                    ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['position']) ?></td>
                            <td><?= htmlspecialchars($row['phone']) ?></td>
                            <td>
                                <a href="edit_employee.php?id=<?= $row['id'] ?>" class="action-btn edit-btn">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="employees.php?delete_id=<?= $row['id'] ?>" class="action-btn delete-btn" 
                                   onclick="return confirm('Are you sure you want to delete this employee?');">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
<div id="addEmployeeModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2><i class="fas fa-user-plus"></i> Add New Employee</h2>
        <form id="employeeForm" method="POST" action="add_employee.php">
            <div class="form-group">
                <label for="name">Full Name:</label>
                <input type="text" id="name" name="name" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="position">Position:</label>
                <input type="text" id="position" name="position" required>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number:</label>
                <input type="tel" id="phone" name="phone" required>
            </div>
            
            <button type="submit" class="submit-btn"><i class="fas fa-save"></i> Save Employee</button>
        </form>
    </div>
</div>
    <script>
        // Toggle Sidebar
        const menuIcon = document.getElementById('menuIcon');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const overlay = document.getElementById('overlay');

        menuIcon.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            mainContent.classList.toggle('shifted');
            overlay.classList.toggle('active');
            
            // Toggle between hamburger and X icon
            if (sidebar.classList.contains('active')) {
                menuIcon.innerHTML = '×';
                menuIcon.classList.add('close');
            } else {
                menuIcon.innerHTML = '☰';
                menuIcon.classList.remove('close');
            }
        });

        // Close sidebar when clicking on overlay
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            mainContent.classList.remove('shifted');
            overlay.classList.remove('active');
            menuIcon.innerHTML = '☰';
            menuIcon.classList.remove('close');
        });

        // Update Current Time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('currentTime').textContent = timeString;
        }
        setInterval(updateTime, 1000);
        updateTime(); // Initialize immediately

        // Toggle Dropdowns in Sidebar
        document.querySelectorAll('.dropdown-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const dropdownContent = this.nextElementSibling;
                dropdownContent.classList.toggle('show');
                
                // Rotate the arrow icon
                const arrow = this.querySelector('.arrow');
                if (arrow) {
                    arrow.style.transform = dropdownContent.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0)';
                }
            });
        });
        // Add this to your existing script section
// Modal functionality
const modal = document.getElementById("addEmployeeModal");
const addBtn = document.querySelector(".add-btn");
const closeModal = document.querySelector(".close-modal");

// Open modal when Add button is clicked
addBtn.addEventListener('click', function() {
    modal.style.display = "block";
});

// Close modal when X is clicked
closeModal.addEventListener('click', function() {
    modal.style.display = "none";
});

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target == modal) {
        modal.style.display = "none";
    }
});

// Form submission handling
document.getElementById('employeeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // You can add form validation here if needed
    
    // Submit form via AJAX for better UX
    const formData = new FormData(this);
    
    fetch('add_employee.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Handle success response
        window.location.reload(); // Refresh to show new employee
    })
    .catch(error => {
        console.error('Error:', error);
    });
});
    </script>
</body>
</html>