# PDO CLASS

This is an abstract PDO class I wrote, intended to be the base for models in an MVC environment.
From past experience, my goal here was to keep the model instances keyed to a specific table. Everything
passing through here is bound to help prevent SQL injections.

I threw this together over a weekend, and have only had some limited time to test, so hopefully I'll be able to flesh it out a bit better in the coming months.

In addition, I added some methods to make it easier to put together simple queries without
too much trouble or even advanced MySQL know-how.

This class assumes most tables have a column called "**rowstate**", which I use to indicate whether a record is published, unpublished, or deleted.
In the future, I plan to make this more customizable as I work on separating this out from my main work and updating this repo.

When this class is extended, the table in use is set in the constructor, like so:

```
public function __construct() {
    parent::__construct('users');
}
```

Now in one of our methods, we can create a simple select just by stringing together the relevant parts needed.

`$this->addSelect('COUNT(*) AS count')->addWhere('username', '=', $userName)->addLimit(1)->getResultScalar();`

`addSelect()` defaults to `*`, so if you're selecting all in a table, you can omit it.

Other useful methods here to make life easier:

| Method         | Arguments    | Results      |
| -------------- | ------------ | ------------ |
| `addSelect()`  | string (normal select) | Only the data selected will be returned. Single items if you want to use `getResultsScalar()` |
| `addWhere()` | column name, operator, comparison value | can be strung together multiple times for more detailed where statements |
| `addJoin()` | joined table name, joined table nickname, array for on statement | Table nicknames for putting full query together, the on array currently only does equals, so the array should be key => value pairs of the style `ON _key_ = _value_` |
| `addGroupBy()` | string (normal group by) | string is passed directly into the `GROUP BY` line, so do what you need to do here |
| `addOrderBy()` | string (normal order by), int direction | Same as `addGroupBy()`, constants are set for the direction (ASC/DESC) |
| `addLimit()` | int limit start, (optional) int limit end | Add a numeric limit, or limit start and stop for a range |
| `getResultsObject()` | none | added to the end of the stringed methods, returns an associative array of the results. |
| `getResultsScalar()` | none | For single value returns, returns just that value without any resultset or array structure. |

If you'd rather put together a more complex query, a few methods have been added to accommodate this as well, with safety being a concern.

|                 |                                           |                                  |
| --------------- | ----------------------------------------- | ---------------------------------|
| `preparedQuery()` | string SQL, array data, int return type | SQL is your normal SQL string. The code assumes you're using the _:Variable_ placeholders instead of _?_. Data should be a key => value where the keys are your placeholders. Return type values are defined as constants in the class.  |
| `preparedQueryScalar()` | string SQL, array data | Same as above, but return type is set to single value. A shortcut. |


Sometimes you just want to run a query without anything returned. In this case, use `runPreparedQuery()` - same arguments as `preparedQueryScalar()` above (since nothing is returned).

Two more "simple" methods were added for quick updates/inserts.

| Method         | Arguments    | Results      |
| -------------- | ------------ | ------------ |
| `simpleInsert()` | array columns, array values | Two arrays are passed. The first containing the columns to be inserted into, the other array with the values to be inserted. This returns the new insert ID for the new value. For multiple inserts, the very last record to be inserted is returned. |
| `simpleUpdate()` | array columns, array values | Same as above, but the number of rows affected is returned instead. |

With our `users` construct above, using the data set above, we can put together a decent query that reads left to right in an understandable fashion without too much effort.
For example:

`$this->addSelect('username', 'email')->addWhere('user_id', '=', $userId)->addLimit(1)->getResultsObject();`

This would return us a numbered array of associative arrays containing the matched username/emails.
