
(function () {
    const database = db.getSiblingDB("SmartMoveDB");
    const schemas = {
    "vehicle_documents": {
        "bsonType": "object",
        "required": [
            "doc_id",
            "vehicle_id",
            "vehicle_plate",
            "document_type",
            "file_path",
            "images"
        ],
        "properties": {
            "doc_id": {
                "bsonType": "string"
            },
            "vehicle_plate": {
                "bsonType": "string"
            },
            "file_path": {
                "bsonType": "string"
            },
            "expiry_date": {
                "bsonType": "date"
            },
            "images": {
                "bsonType": "array",
                "items": {
                    "bsonType": "string"
                }
            },
            "metadata": {
                "bsonType": "object"
            }
        }
    },
    "passenger_reviews": {
        "bsonType": "object",
        "required": [
            "review_id",
            "passenger_id",
            "passenger_name",
            "trip_id",
            "route_id",
            "vehicle_id",
            "driver_id",
            "rating",
            "comment",
            "created_at"
        ],
        "properties": {
            "review_id": {
                "bsonType": "string"
            },
            "rating": {
                "bsonType": [
                    "int",
                    "long",
                    "double",
                    "decimal"
                ],
                "minimum": 1,
                "maximum": 5,
                "multipleOf": 1
            },
            "comment": {
                "bsonType": "string",
                "minLength": 1,
                "maxLength": 2000
            },
            "created_at": {
                "bsonType": "date"
            },
            "tags": {
                "bsonType": "array",
                "items": {
                    "bsonType": "string"
                }
            },
            "replies": {
                "bsonType": "array",
                "items": {
                    "bsonType": "object"
                }
            }
        }
    },
    "travel_announcements": {
        "bsonType": "object",
        "required": [
            "title",
            "message",
            "priority",
            "published_date",
            "audience"
        ],
        "properties": {
            "announcement_id": {
                "bsonType": "string"
            },
            "priority": {
                "enum": [
                    "Low",
                    "Medium",
                    "Normal",
                    "High",
                    "Urgent"
                ]
            },
            "published_date": {
                "bsonType": "date"
            },
            "audience": {
                "oneOf": [
                    {
                        "bsonType": "string"
                    },
                    {
                        "bsonType": "array",
                        "items": {
                            "bsonType": "string"
                        }
                    }
                ]
            }
        }
    },
    "discussion_threads": {
        "bsonType": "object",
        "required": [
            "thread_id",
            "title",
            "author",
            "posts",
            "created_at"
        ],
        "properties": {
            "thread_id": {
                "bsonType": "string"
            },
            "author": {
                "bsonType": "object"
            },
            "posts": {
                "bsonType": "array",
                "items": {
                    "bsonType": "object"
                }
            },
            "created_at": {
                "bsonType": "date"
            }
        }
    },
    "vehicle_maintenances": {
        "bsonType": "object",
        "required": [
            "maintenance_id",
            "vehicle_id",
            "maintenance_date",
            "description",
            "cost"
        ],
        "properties": {
            "maintenance_id": {
                "bsonType": [
                    "int",
                    "long",
                    "double",
                    "decimal"
                ],
                "minimum": 1
            },
            "vehicle_id": {
                "bsonType": [
                    "int",
                    "long",
                    "double",
                    "decimal"
                ],
                "minimum": 1
            },
            "maintenance_date": {
                "bsonType": "string",
                "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$"
            },
            "description": {
                "bsonType": "string"
            },
            "cost": {
                "bsonType": [
                    "int",
                    "long",
                    "double",
                    "decimal"
                ],
                "minimum": 0
            },
            "service_center": {
                "bsonType": "string"
            },
            "updated_at": {
                "bsonType": "date"
            }
        }
    },
    "activities": {
        "bsonType": "object",
        "properties": {
            "user": {
                "bsonType": "string"
            },
            "action": {
                "bsonType": "string"
            },
            "timestamp": {
                "bsonType": "date"
            }
        }
    }
};
    const names = Object.keys(schemas);
    const existing = database.getCollectionNames();
    if (names.some(function (name) { return existing.includes(name); })) {
        throw new Error("SmartMove collections already exist. Do not run fresh setup on this database.");
    }
    names.forEach(function (name) {
        database.createCollection(name, {
            validator: { $jsonSchema: schemas[name] },
            validationLevel: "strict", validationAction: "error"
        });
    });
    database.vehicle_documents.createIndex({doc_id: 1}, {unique: true});
    database.vehicle_documents.createIndex({vehicle_id: 1, uploaded_at: -1});
    database.vehicle_documents.createIndex({expiry_date: 1});
    database.passenger_reviews.createIndex({review_id: 1}, {unique: true});
    database.passenger_reviews.createIndex({route_id: 1, created_at: -1});
    database.passenger_reviews.createIndex({vehicle_id: 1});
    database.passenger_reviews.createIndex({driver_id: 1});
    database.passenger_reviews.createIndex({passenger_id: 1, trip_id: 1}, {unique: true});
    // Admin writes use _id and omit announcement_id. Optional sample IDs are unique.
    database.travel_announcements.createIndex({announcement_id: 1}, {unique: true, sparse: true});
    database.travel_announcements.createIndex({route_id: 1, published_date: -1});
    database.travel_announcements.createIndex({audience: 1, published_date: -1});
    database.discussion_threads.createIndex({thread_id: 1}, {unique: true});
    database.vehicle_maintenances.createIndex({maintenance_id: 1}, {unique: true});
    database.vehicle_maintenances.createIndex({vehicle_id: 1, maintenance_date: -1});
    database.activities.createIndex({timestamp: -1});
    print("SmartMoveDB collections, validators and indexes created. Demo data is optional.");
})();
