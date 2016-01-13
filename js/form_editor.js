( function( $ ){
	
	$( document ).ready( function() {
		
		var folder_tree = $( '.folder_tree' ),
			first_load = true;

		folder_tree.on( 'select_node.jstree', function( e, jstree ){
			
			$( 'input[name="' + folder_tree.attr( 'data-target' ) + '"]' ).val( jstree.node.id );
			
		});
		
		folder_tree.jstree( {
		 	'core' : {
		 		'data' : {
		 			'url':			ajaxurl,
		 			'dataType':		'JSON',
		 			'data':			function (node) {
		 				return {
			 				'action':		'gfdropbox_folder_contents',
			 				'first_load':	first_load,
			 				'path': 		( node.id == '#' ) ? gfdropbox_path : node.id
			 			};
		 			},
		 			'success':		function () {
			 			first_load = (first_load == true) ? false : first_load; 
			 		}
		 		}
			}
		} );
		
	} );
	
} )( jQuery );