<!DOCTYPE html>
<html lang="en">
<head>
<title>Printing (Cheques)</title>
<meta charset="utf-8">
<style type="text/css">
.wrapper{
	-webkit-transform: rotate(-90deg);
	-moz-transform: rotate(-90deg);
	-o-transform: rotate(-90deg);
	-ms-transform: rotate(-90deg);
	transform: rotate(-90deg);
	margin-top: 1.3in;
	margin-left:-3.5in;
}

#cf_date{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:0.25in;
	margin-left:-1.25in;
}
#cf_payee{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:0.80in;
	margin-left:-1.50in;
	width:1.3in;
}
#cf_remarks{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:1.40in;
	margin-left:-1.75in;
	width: 1.5in;
}
#cf_amount{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:2.60in;
	margin-left:-1.00in;
}

#ac_pay{
	font-family: Arial;
	font-size:14px;
	position:absolute;
	margin-top:0.25in;
	margin-left:3.00in;
	text-align:center;
	border-top: 1px solid #FF0000;
	border-bottom: 1px solid #FF0000;
	color: #FF0000;
}
#ac_payc{
	position:absolute;
	margin-top:-0.1in;
	margin-left:-0.2in;
}
#ac_pay span{
	display: block;
	line-height:1.4em !important;
	font-size:10px;
}
#date{
	font-family: Arial;
	font-size:16px;
	position:absolute;
	margin-top:0.25in;
	margin-left:4.90in;
}
#payee{
	position:absolute;
	margin-top:0.80in;
	margin-left:0.80in;
	font-family: "Arial";
	font-size: 14px;
}
#amount{
	position:absolute;
	margin-top:1.50in;
	margin-left:5.00in;
	font-family: "Arial";
	font-size: 16px;
}
#amount_words{
	position:absolute;
	margin-top:1.15in;
	margin-left:0.80in;
	font-family: "Arial";
	font-size: 14px;
	width: 3.5in;
	line-height:0.29in;
	letter-spacing:1px;
	text-align:justify;
}

#bearer{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:1.00in;
	margin-left:4.80in;
}
#seal{
	position:absolute;
	margin-top:1.90in;
	margin-left:4.50in;
}
#logo{
	position:absolute;
	margin-top:2.30in;
	margin-left:2.50in;
}
#logo img{
	}

.digit{
	width:0.25in; /* change this value to adjust digit width in the date */
	float:left;
	line-height:0.25in;
	text-align:center;
}
.clear{
	clear:both;
}

#overlay {
  position: fixed; /* Sit on top of the page content */
  display: block; /* Hidden by default */
  width: 100%; /* Full width (cover the whole page) */
  height: 100%; /* Full height (cover the whole page) */
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0,0,0,0.5); /* Black background with opacity */
  z-index: 2; /* Specify a stack order in case you're using a different order for other elements */
  cursor: pointer; /* Add a pointer on hover */
}

@media print{
    #overlay{display:none;}
}

a:link, a:visited {
  background-color: #f44336;
  color: white;
  padding: 14px 25px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
}

a:hover, a:active {
  background-color: red;
}

</style>
</head>
<body>
<div id="overlay"><a href="#" onclick="javascript:chqPrint();">Re-Print</a> <a href="#" onclick="javascript:chqClose();">Close</a></div>

<div class="wrapper">

	<!-- counter foil -->
			
	<!-- // end counter foil -->

	<!-- cheque details -->
	
							<div id="ac_payc">
				<img src="images/cross.png" />
			</div>

			<div id="ac_pay">A/C PAYEE ONLY</span></div>
				
		<div id="date">
			<span class="digit">2</span>
			<span class="digit">4</span>
			<span class="digit">0</span>
			<span class="digit">6</span>
						<span class="digit">&nbsp;</span>
			<span class="digit">&nbsp;</span>
						<span class="digit">2</span>
			<span class="digit">6</span>
			<div class="clear"></div>
		</div>
		<div id="payee">**Test Account**</div>
		<div id="amount">**20,000.00**</div>
		<div id="amount_words">**Twenty Thousand Only**</div>
	<!-- // end cheque details -->
	
	<!-- extra details -->
	
					<div id="bearer"></div>
				
				
			<!-- // end extra details -->
	
</div>
<script type="text/javascript">
setTimeout(function(){
	chqPrint();
},2000);

function chqPrint(){
    window.print();
}

function chqClose(){
    		window.location="print.php";
	}

function on() {
  document.getElementById("overlay").style.display = "block";
}

function off() {
  document.getElementById("overlay").style.display = "none";
}
</script>
</body>
</html>