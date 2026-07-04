function openFilterNav() 
{
	document.getElementById("FilterSideNav").style.width = "350px";
}

function closeFilterNav() 
{
	document.getElementById("FilterSideNav").style.width = "0";
}


function open_mr_edit(data_id) 
{
	document.getElementById("mr_edit_main_div").style.width = "100%";
  
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id);
}

function close_mr_edit() 
{
	document.getElementById("mr_edit_main_div").style.width = "0%";
}

function open_review_edit(data_id) 
{
	document.getElementById("review_edit_main_div").style.width = "100%";
  
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id);
}

function close_review_edit() 
{
	document.getElementById("review_edit_main_div").style.width = "0%";
}

function submit_add(data_id) 
{
	if( $("#new_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									new_map_code: $("#new_map_code").val(),
									new_map_vocabulary: $("#new_map_vocabulary").val(),
									submit_add : 1
								});
}

function submit_update(data_id, map_id) 
{
	if( $("#update_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									map_id: map_id,
									update_map_code: $("#update_map_code_" + map_id).val(),
									update_map_vocabulary: $("#update_map_vocabulary_" + map_id).val(),
									submit_update : 1
								});
}

function submit_delete(data_id, map_id) 
{
	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									map_id: map_id,
									submit_delete : 1
								});
}

function submit_excl_update(data_id) 
{
	var exclude_status = $("input[type=radio][name=exclude_status]:checked").val();
	if ( !exclude_status ) 
	{
		alert('Nothing is selected');
		return false;
	}

	$("#mr_edit_content_div").load("edit_mr_item.php?id=" + data_id, 
								{
									exclude_status: exclude_status,
									comment_text: $("#comment_text").val(),
									exclude_status_submit : 1
								});
}








function submit_add_rev(data_id) 
{
	if( $("#new_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									new_map_code: $("#new_map_code").val(),
									new_map_vocabulary: $("#new_map_vocabulary").val(),
									submit_add : 1
								});
}

function submit_update_rev(data_id, map_id) 
{
	if( $("#update_map_code").val() == "" )
	{
		alert("Please specify the concept code");
		return false;
	}
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									map_id: map_id,
									update_map_code: $("#update_map_code_" + map_id).val(),
									update_map_vocabulary: $("#update_map_vocabulary_" + map_id).val(),
									submit_update : 1
								});
}

function submit_delete_rev(data_id, map_id) 
{
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									map_id: map_id,
									submit_delete : 1
								});
}

function submit_excl_update_rev(data_id) 
{
	var exclude_status = $("input[type=radio][name=exclude_status]:checked").val();
	if ( !exclude_status ) 
	{
		alert('Nothing is selected');
		return false;
	}

	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									exclude_status: exclude_status,
									comment_text: $("#comment_text").val(),
									exclude_status_submit : 1
								});
}

function submit_approve_rev(data_id) 
{
	$("#review_edit_content_div").load("edit_review_item.php?id=" + data_id, 
								{
									submit_approve : 1
								});
}



