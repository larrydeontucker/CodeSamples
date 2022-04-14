<?php
/**
 * PHP v7.4
 *
 * This script updates the e-commerce website inventory with the main database.
 * 
 */

// Increase time to gather infomation
set_time_limit(2600);

// Establish database connections
$main = databaseMain();
$link = databaseLink();

// Synchronize manufacturers using the main database
$manufacturers = getManufacturer($main);
setManufacturer($link, $manufacturers);

clearInventory();

$costSPQ = arrayCostSpq();
$date = date("y-m-d");

$inventory = getInventory($main);

// Main Loop
while($row = $inventory->fetch()) {
    $item = $row["Item_No"];
    $brand	= $row["Brand"];
    $vendor = $row["Vendor Item No"];
    $short = $row["Short Description"];
    $long = str_replace("'","''",$row["Long Description"]);
    $qty = $row["Qty"];
    $moq = $row["Spq"];
    $spq = $row["Spq"];
    $local = str_replace("'","''",$iVendor_Item);
    $safe = str_replace("'","''",$iItem_No);

    $flag = "Y";

    $cost = getPrice($main, $item, $brand);
    $margin = getMargin($link, $brand);
    $price = setPrice($link, $cost, $margin);

    $weight = calculateWeight($price, $spq, $costSPQ);
    
    $mainCategory = getCategory($main, $item, $brand);

    $reserved = getReserveQty($main, $item, $brand);
    $quantity = calculateQty($reserved, $qty);
    $flag = validateQty($quantity, $flag);

    $moqSPQ = validateMoqSpq($spq, $moq, $qty, $flag);
    $moq = $moqSPQ['Moq'];
    $spq = $moqSPQ['Spq'];

    $itemFlag = validateItem($link, $item);
    
    if ($itemflag) {
        $operator = 'UPDATE';
    } else {
        $operator = 'INSERT';
    }

}

// Main Database Connection
function databaseMain() {
    // main database config information
    $dbConnect = array(
        'dsn' => "mysql:host=127.0.0.1;dbname=main",
        'username' => "admin",
        'password' => "password"
    );
    
    try {
        $database = new PDO(
            $dbConnect['dsn'], 
            $dbConnect['username'], 
            $dbConnect['password']
        );
        echo "Main Connection successful ... \n";

    } catch (PDOException $error) {
        die("Main Connection failed: " . $error->getMessage());
    }

    return $database;
}

// Link Database Connection (E-Commerce Website)
function databaseLink() {
    // link database config infomation
    $dbConnect = array(
        'dsn' => "mysql:host=localhost;dbname=store",
        'username' => "admin",
        'password' => "password"
    );
    
    try {
        $database = new PDO(
            $dbConnect['dsn'], 
            $dbConnect['username'], 
            $dbConnect['password']
        );
        echo "Link Connection successful ... \n";

    } catch (Exception $error) {
        die("Link Connection failed: " . $error->getMessage());
    }

    return $database;
}

// Get manufacturer information from the main database
function getManufacturer($database) {
    // query all manufacturer names
    $sql  = "SELECT DISTINCT currinventory.brand AS name 
        FROM currinventory, plm
        WHERE currinventory.Brand=plm.Brand 
        AND plm.Franch='F' 
        ORDER BY currinventory.brand";

    $result = $database->prepare($sql);
    
    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Main Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

// Set Manufacturer information for the link database
function setManufacturer($database, $manufacturers) {
    // cycle through the manufacturers in the main database
    while($row = $manufacturers->fetch()) {
        $counter = 0;
        $brand = $row["name"];
      
        // check the manufacturer exists in the link database
        $sql = "SELECT * FROM xc_tag_translations tt WHERE tt.name='$brand' ";
        $query = $database->prepare($sql);
    
        try {
            $query->execute();
            
        } catch (PDOException $error) {
            die("Link Connection Query Attempt failed: " . $error->getMessage());
        }

        // this manufacturer does exist in the link database
        while($rowLink = $query->fetch()) {
            $counter++;
        }

        // this manufacturer does not exist in the link database
        if($counter <= 0) {
            echo $counter;

            // add this new manufacturer to the link database
            $addLabel = "INSERT INTO xc_tag_translations (name, code) VALUES('$brand', 'en') ";
            $addID = "INSERT INTO xc_tags (position) VALUES (0)";
            $addIDSync = "UPDATE xc_tag_translations tt 
                LEFT JOIN xc_tags t 
                ON tt.label_id = t.id
                SET tt.id = t.id 
                WHERE tt.name = '$brand'";

            try {
                $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $database->beginTransaction();

                $database->exec($addLabel);
                $database->exec($addID);
                $database->exec($addIDSync);
                
                $database->commit();
                print("Transaction of manufacturers completed");

            } catch (Exception $error) {
                $database->rollback();
                print("Transaction of manufacturers not completed" . $error->getMessage());
            }
        }
    }
}

// Clear the inventory stock amounts for the link database
function clearInventory($database) {
    $sql = "UPDATE xc_products SET amount = 0";
    $result = $database->prepare($sql);

    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Link Connection Query Attempt failed: " . $error->getMessage());
    }
}

// Get the updated inventory from the main database
function getInventory($database) {
    // query all the product information needed for the update
    $sql  = "SELECT currinventory.Item_No,
        currinventory.Brand, 
        inventory.`Vendor Item No`, 
        inventory.`Short Description`, 
        inventory.`Long Description`,
        inventory.Moq, inventory.Spq, 
        SUM(currinventory.Quantity) AS Qty 
        FROM currinventory, inventory
        WHERE (currinventory.Organization_Code='326' 
            OR currinventory.Organization_Code = '323' 
            OR currinventory.Organization_Code='321')
        AND (currinventory.Subinventory_Code = 'COMMON' 
            OR currinventory.Subinventory_Code = 'COMMON-MI')  
        AND currinventory.Item_No = inventory.`Item No`   
        AND currinventory.Brand = inventory.Brand   
        AND currinventory.Item_Cost > 0   
        GROUP BY currinventory.Item_No, 
            currinventory.Brand,           
            inventory.`Vendor Item No`, 
            inventory.`Short Description`, 
            inventory.`Long Description`,         
            inventory.Moq, 
            inventory.Spq ";

    $result = $database->prepare($sql);

    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Main Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

// Get the raw cost(price) from the main database used to set an item price
function getPrice($database, $item_no, $brand) {
    $sql = "SELECT Item_Cost 
        FROM currinventory 
        WHERE Item_No = '$item_no' 
        AND Brand= '$brand' 
        AND (Subinventory_Code = 'COMMON' OR Subinventory_Code = 'COMMON-MI')  
        AND (Organization_Code = '326' OR Organization_Code = '323' OR Organization_Code = '321') 
        AND Item_Cost > 0";

    $result = $database->prepare($sql);

    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Main Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

// Get the margin from the link database used to set an item price
function getMargin($database, $brand) {
    $sql = "SELECT multiplier AS Margin, 
        active AS ACT 
        FROM wpga_margin 
        WHERE brand = '$brand'";

    $result = $database->prepare($sql);

    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Link Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

// Set the price for the link database item using the raw cost and margin
function setPrice($database, $cost, $margin) {
    $localCost = 0;

    $DefaultMargin = 1.50;

    while($rowCost = $cost->fetch()) {

        while($rowMargin = $margin->fetch()) {
            $DefaultMargin = floatval($rowMargin['Margin']);
            $ActiveFlag    = $rowMargin['ACT'];
        }

        // Change cost calculation to use GP instead of Markup for anything under 100%
        $NewGP = $DefaultMargin - 1;

        // GP% Markup Calculation
        // For any Margins greater than 100%, use old Markup calulcation
        if ($NewGP > 0 && $NewGP < 1.0) {
            $localCost = $rowCost['Item_Cost'] / (1 - $NewGP);
        } else {
            $localCost = $rowCost['Item_Cost'] * $DefaultMargin;
        }
    }

    return $localCost;
}

// Look for an online special for this part.  If available then put into price and break
function getSpecialPrice($database, $brand, $safe_item) {
    $sql = "SELECT * FROM onlinespecial 
        WHERE Brand = '$brand' 
        AND Item_No = '$safe_item'";

    $result = $database->prepare($sql);

    try {
        $result->execute();
    } catch (PDOException $error) {
        die("Link Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

/**
 * In the original, how do we know which price to use? 
 * Where are we returning a value(price) to?
 */
function setSpecialPrice($database, $special) {
    $tempPrice = "";

    while($row = $special->fetch()) {
        $tempPrice = $row['Resale'];
        $localCost = $tempPrice;
    }

    return $localCost;
}

// Set cost, spq array used for weight calculations
function arrayCostSpq() {
    $row0 = array(.0001875, .0001875, .0005, .0005, .00075);
    $row1 = array(.000302033, .0005, .00075, .001, .0025);
    $row2 = array(.000302033, .0005, .001, .0075, .01);
    $row3 = array(.0005, .0005, .001, .01, .02);
    $row4 = array(.001, .001, .25, .25, 1);
    $row5 = array(.1, .5, .5, 2, 2.5);
    $row6 = array(1, 1.5, 1.75, 2.5, 3);
    $row7 = array(3, 3.5, 4, 4.5, 5);

    return array($row0, $row1, $row2, $row3, $row4, $row5, $row6, $row7);
}

function calculateWeight($cost, $spq, $cost_spq) {
    //default weight to 3 pounds
    $weight = 3.0;
    $localCost = floatval($this->cost);
    $localSPQ = floatval($this->spq);

    $costSPQ = $cost_spq;

    $UCLow = array(0, .01, .10, 1.0, 10.0, 50.0, 100.0, 500.0);
    $UCHigh = array(.01, .10, 1.0, 10.0, 50.0, 100.0, 500.0, 99999999.9);
    
    $SPQLow = array(0, 10.0, 500.0, 1000.0, 2500.0);
    $SPQHigh = array(10.0, 500.0, 1000.0, 2500.0, 99999999.9);

    $currUC = 0;
    $currSPQ = 0;

    for($x = 0; $x < count($UCLow); $x++) {
        if (($localCost >= $UCLow[$x]) && ($localCost < $UCHigh[$x])) {
            $currUC = $x;
        }
    }

    for($x = 0; $x < count($SPQLow); $x++) {
        if (($localSPQ >= $SPQLow[$x]) && ($localSPQ < $SPQHigh[$x])) {
            $currSPQ = $x;
        }
    }

    $weight = floatval($costSPQ[$currUC][$currSPQ]);

    return $weight;
}

function getCategory($database, $item_no, $brand) {
    $sql = "SELECT Category_1 
        FROM currinventory 
        WHERE currinventory.Item_No='$item_no' 
        AND currinventory.Brand='$brand' 
        LIMIT 1";

    $result = $database->prepare($sql);

    try {
        $result->execute();
    } catch (PDOException $error) {
        die("Main Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

/**
 * Rewrite thisfunction to set the category for the link database schema
 * 
 */
function setCategory($database, $category, $flag) {
    while($row = $category->fetch()) {
        $currCat = "";
        $localCat = $row['Category_1'];

        $sql  = "SELECT category_id 
            FROM category_description, wpg_category 
            WHERE wpg_category.category_1 = '$localCat' 
            AND wpg_category.store_category = category_description.name ";

        $result = $database->prepare($sql);

        try {
            $result->execute();
        } catch (PDOException $error) {
            die("Link Connection Query Attempt failed: " . $error->getMessage());
        }
    
        while($rowCategory = $result->fetch()) {
            $currCat = $rowCategory["category_id"];
        }	 
        
        /**
         * Create category validation function
         * returns flag status
         */
        // Set the active flag to inactive if the category_1 links to a category called EXCLUDE NF
        if (strcmp($currCat,"EXCLUDE NF") == 0){
            $flag = "N";   // Override to inactive part and don't create anything for this part
        } 
        
    }
}

// Get an item reserved quantity from the main database
function getReserveQty($database, $item_no, $brand) {
    $sql = "SELECT * FROM Reservation_Details 
        WHERE Item_No='$item_no' 
        AND Brand='$brand' 
        AND (Demand_Type='Inventory' OR Demand_Type='Sales order')
        AND (WH_Org='326' OR WH_Org='323' OR WH_Org='321') 
        AND (Subinventory='COMMON' OR Subinventory='' 
        OR IsNull(Subinventory)) ";

    $result = $database->prepare($sql);

    try {
        $result->execute();
    } catch (PDOException $error) {
        die("Main Connection Query Attempt failed: " . $error->getMessage());
    }

    return $result;
}

// Calculate the actual quantity using the reserved quantity
function calculateQty($reserved, $quantity) {
    $pLck_Qty = 0;

    while($row = $database->fetch()) {
        $bLock	= intval($row['Reservation_Quantity']);
        $pLck_Qty = $pLck_Qty + $bLock;
    }

    $quantity = $quantity - $pLck_Qty;

    if($quantity <= 0) {
        $quantity = 0;
    }

    return $quantity;
}

// Check if an item should be disabled
function validateQty($quantity, $flag) {
    if($quantity <= 0) {
        $flag = "N";
    }

    return $flag;
}


// Check if Qty Avail is less than MOQ/SPQ (broken packs).  If so then make MOQ/SPQ equal to Inventory
function validateMoqSpq($spq, $qty, $flag) {
    $arraySpqMoq = array(
        'Spq' => $spq,
        'Moq' => $spq 
    );

    if (strcmp($flag,"Y") == 0) {
        $TestSPQ2 = floatval($spq);

        if ($qty < $TestSPQ2){
            $arraySpqMoq['Spq'] = $qty;
            $arraySpqMoq['Moq'] = $qty;
        }
    }  

    return $arraySpqMoq;
}

// Check if this item exists
function validateItem($link, $item_no) {
    $flag = false;

    $sql = "SELECT sku 
        FROM xc_products
        WHERE sku = '$item_no'";

    $result = $database->prepare($sql);
        
    try {
        $result->execute();

    } catch (PDOException $error) {
        die("Link Connection Query Attempt failed: " . $error->getMessage());
    }

    if (!empty($result)) {
        $flag = true;
    }

    return $flag;
}

// Create keyword operator based on an items existence
function updateOperator($flag) {
    $operator = '';

    if ($flag) {
        $operator = 'UPDATE';
    } else {
        $operator = 'INSERT';
    }

    return $operator;
}
?>
