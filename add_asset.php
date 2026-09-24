<!-- ADD ICT ASSET -->
<div id="add-asset" class="section">
<h2>Register ICT Asset</h2>
<style>
    /* General Page Styling */
body {
    font-family: Arial, Helvetica, sans-serif;
    background:linear-gradient(to right,#b08116,#99bb4f);
    margin: 0;
    padding: 0;
}

/* Section Container */
.section {
    width: 500px;
    margin: 50px auto;
    background: #ffffff;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

/* Title */
.section h2 {
    text-align: center;
    margin-bottom: 25px;
    color: #0b3d91;
    font-weight: 600;
}

/* Form Inputs */
.section input,
.section select {
    width: 100%;
    padding: 10px;
    margin: 8px 0 15px 0;
    border: 1px solid #dcdcdc;
    border-radius: 6px;
    font-size: 14px;
    transition: 0.3s ease;
}

/* Focus Effect */
.section input:focus,
.section select:focus {
    border-color: #b08116;
    outline: none;
    box-shadow: 0 0 5px rgba(94, 196, 17, 0.2);
}

/* Labels */
.section label {
    font-size: 13px;
    font-weight: 600;
    color: #555;
}

/* Button */
.section button {
    width: 100%;
    padding: 12px;
    background-color: #0b3d91;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    cursor: pointer;
    transition: 0.3s ease;
}

/* Button Hover */
.section button:hover {
    background-color: #062d6b;
}

/* Responsive */
@media (max-width: 600px) {
    .section {
        width: 90%;
        margin: 20px auto;
        padding: 20px;
    }
}
</style>
<form method="POST">

<input type="text" name="asset_tag" placeholder="Asset Tag" required>
<input type="text" name="serial_number" placeholder="Serial Number" required>
<input type="text" name="brand" placeholder="Brand">
<input type="text" name="model" placeholder="Model">

<select name="status">
<option value="Active">Active</option>
<option value="Faulty">Faulty</option>
<option value="Retired">Retired</option>
</select>

<label>Purchase Date</label>
<input type="date" name="purchase_date">

<label>Warranty Expiry</label>
<input type="date" name="warranty_expiry">

<button type="submit" name="add_asset">Register Asset</button>

</form>
</div>